<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\DocumentGraph;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Glob;
use Docuccino\Core\Support\PlainText;
use Docuccino\Core\Versioning\VersionOrder;
use Docuccino\Laravel\Config\ConfiguredDocuments;
use Docuccino\Laravel\Support\ListValueNames;

/**
 * Turns the document a build just assembled into the document for the API version it declares: every
 * declared change that shipped AFTER this version is applied in REVERSE, and every operation declares
 * the header a client pins a version with. A document with no `api_version` is not an API version, and
 * this moves not a byte of it.
 *
 * The one thing to know before reading it is the fork rule a scoped change follows, which
 * `docs/design/api-versioning.md` states and justifies.
 *
 * @phpstan-import-type OperationSite from DocumentGraph
 *
 * @internal
 */
#[ExtensionOrder(priority: Priorities::LATE)]
final readonly class ApiVersionTransformer implements DocumentTransformer
{
    /** The components bucket the one header declaration is published in, and where a `$ref` finds it. */
    private const string PARAMETERS = 'parameters';

    private const string PARAMETER_REF = '#/components/parameters/';

    /** The registration slot the one claim on a component name is made under. */
    private const string CLAIM = 'api-version-header';

    /**
     * What the header says to somebody who cannot see the codebase — which is why it names no attribute,
     * no config key and no way to change it.
     */
    private const string DESCRIPTION = 'The API version this request is answered as. Omit it and the request is answered as the version this document describes.';

    public function __construct(
        private VersionChangeCollector $changes,
        private ConfiguredDocuments $documents = new ConfiguredDocuments,
        private IdentityGenerator $identity = new IdentityGenerator,
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        $config = $context->config;
        if (! $config->declaresApiVersion()) {
            return;
        }

        $version = $config->apiVersion();
        if ($version === null) {
            // There is no version to derive, and inventing one would put a version the application does
            // not serve into every operation's enum AND make it the default a client falls back to. So
            // this document stays exactly what it was.
            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'versioning.version-unstated',
                message: sprintf(
                    'The "%s" document declares api_version but states no info.version, so it was not derived as an API version.',
                    PlainText::of($config->key),
                ),
                help: sprintf(
                    'Set documents.%s.info.version to the version this document describes — that value IS the API version.',
                    PlainText::of($config->key),
                ),
            ));

            return;
        }

        // One change is walked once per rename, and a report about the change rather than about the
        // field it names — a scope that matched nothing — comes out identical every time. Two copies of
        // one sentence tell the reader nothing they did not have after the first, so each is said once.
        $said = [];

        $set = $this->changes->collect($config);
        foreach ($set->diagnostics as $diagnostic) {
            self::reportOnce($context, $diagnostic, $said);
        }

        $doc = $document->toArray();

        // The code is the newest version, so an older document is the code with every LATER change
        // undone — newest first, each handing the shape of the version below it to the next.
        foreach ($set->after($version) as $change) {
            // In the order {@see VerbOrder} settles, which is the whole of what "the author's written
            // order" comes to once an AttributeSet has answered per type.
            foreach ($change->verbs as $verb) {
                $doc = $change->selectors === []
                    ? $this->apply($doc, $verb, $change, $context, $said)
                    : $this->applyScoped($doc, $verb, $change, $context, $said);
            }
        }

        $document->replace($this->declareVersionHeader($doc, $context, $version, $set->changes, $set->order));
    }

    /**
     * Applies one verb wherever the document publishes the schema it names — the hoisted component and
     * any inline copy of it alike, matched by the schema's own identity rather than by the property
     * name, so a `title` on an unrelated schema is never touched.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, true>  $said
     * @return array<string, mixed>
     */
    private function apply(array $doc, VersionVerb $verb, VersionChange $change, DocumentContext $context, array &$said): array
    {
        $id = $verb->identity($this->identity);

        // The examples first, guided by the schemas as the code publishes them: moving a property
        // first would leave the walk looking for one that has already moved.
        [$rewritten, $dropped] = $verb->rewriteDocumentExamples($doc, $id, $change);

        $outcome = VerbOutcome::Unresolved;
        $cyclic = false;
        $published = new PublishedSchemas($rewritten, $this->identity);

        // From the members rather than the root: the root's own `x-docuccino` describes the document,
        // and no schema's identity can be there. Nothing reaches, so nothing expands: an unscoped verb
        // is the walk with its `$ref` half switched off.
        foreach ($rewritten as $key => $value) {
            if (is_array($value)) {
                $rewritten[$key] = $this->rewrite($value, $published, $id, $verb, [], [], $outcome, $cyclic);
            }
        }

        $this->reportOutcome($outcome, $verb, $change, $published, $context, $said);

        // A verb nothing could apply leaves every schema at the shape the code publishes, so its
        // examples belong there too: dropping one for a change that moved nothing costs a reader an
        // example and tells them nothing they were not already told above.
        if ($outcome !== VerbOutcome::Applied) {
            return $doc;
        }

        self::reportAll($context, $dropped, $said);

        return $rewritten;
    }

    /**
     * Applies a change that `#[AppliesTo]` narrows to some operations, under the fork rule
     * `docs/design/api-versioning.md` states. What the design doc does not cover is what happens when
     * the scope decides nothing — each refusal below says why it is a refusal rather than a widening.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, true>  $said
     * @return array<string, mixed>
     */
    private function applyScoped(array $doc, VersionVerb $verb, VersionChange $change, DocumentContext $context, array &$said): array
    {
        $id = $verb->identity($this->identity);
        $reaches = DocumentGraph::componentsReaching($doc, $id);

        $reaching = [];
        foreach (DocumentGraph::operationSites($doc) as $index => $site) {
            $operation = DocumentGraph::at($doc, $site['keys']);
            if (is_array($operation) && DocumentGraph::nodeReaches($operation, $id, $reaches)) {
                $reaching[$index] = $site;
            }
        }

        if ($reaching === []) {
            // Refused rather than handed to the unscoped path, which would rename the schema DOCUMENT
            // WIDE — for every operation `#[AppliesTo]` was written to exclude. A scope silently doing
            // the opposite of narrowing is not an acceptable degradation, so the document is left as
            // the code publishes it and the build says which of the two things is wrong.
            self::reportOnce($context, DocumentGraph::carries($doc, $id)
                ? VerbDiagnostics::publishedForNoOperation($change, $verb)
                : VerbDiagnostics::schemaUnresolved($change, $verb), $said);

            return $doc;
        }

        $matched = [];
        foreach ($reaching as $index => $site) {
            if (self::names($change, $site)) {
                $matched[$index] = true;
            }
        }

        foreach ($change->selectors as $selector) {
            if (! self::namesAny([$selector], $reaching)) {
                self::reportOnce($context, self::matchesNothing($change, $selector, $verb), $said);
            }
        }

        if ($matched === []) {
            return $doc;
        }

        if (count($matched) === count($reaching)) {
            return $this->apply($doc, $verb, $change, $context, $said);
        }

        // Two use sites can address ONE node — a path item written as a `$ref` into
        // `components.pathItems`, used by more than one path. Both matched, that node is forked once:
        // a second pass over a node already carrying the older name finds nothing left to rename and
        // would report a rotted declaration that is nothing of the kind.
        $written = [];

        foreach (array_keys($matched) as $index) {
            $node = implode("\0", $reaching[$index]['keys']);
            if (isset($written[$node])) {
                continue;
            }
            $written[$node] = true;

            // And where the node is shared with an operation the scope left OUT, writing the copy there
            // would write it for that one too — the document-wide rename again in miniature, refused for
            // the same reason.
            if (self::sharedWithExcluded($reaching[$index], $reaching, $matched)) {
                self::reportOnce($context, self::unforkable($change, sprintf(
                    'the operation "%s" is published through a path item it shares with operations the scope leaves out, so it cannot be given a copy of the schema for %s and was left at the shape the code publishes',
                    PlainText::of($reaching[$index]['signature'] ?? implode('/', $reaching[$index]['keys'])),
                    PlainText::of($verb->schema()),
                )), $said);

                continue;
            }

            $doc = $this->fork($doc, $reaching[$index], $id, $verb, $reaches, $change, $context, $said);
        }

        return $doc;
    }

    /**
     * Whether this change's scope names the operation. {@see Glob} is the product's one wildcard
     * grammar — the one `routes.include`/`routes.exclude` have always spoken — and a scope reading `*`
     * differently from the route filters would be a config entry that means one thing to the author and
     * another to the build.
     *
     * @param  OperationSite  $site
     */
    private static function names(VersionChange $change, array $site): bool
    {
        foreach (self::spellings($site) as $name) {
            if (Glob::matchesAny($change->selectors, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any of these operations goes by one of the entries — the same reading, asked of one entry
     * at a time, so a selector that decided nothing can be named on its own.
     *
     * @param  list<string>  $entries
     * @param  array<int, OperationSite>  $sites
     */
    private static function namesAny(array $entries, array $sites): bool
    {
        foreach ($sites as $site) {
            foreach (self::spellings($site) as $name) {
                if (Glob::matchesAny($entries, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The names a selector may call an operation by. A webhook has no signature — it is a request the
     * SERVER makes, and no client pins it — so it is spelled by its operationId or not at all, and never
     * by an empty string a `*` would happily match.
     *
     * @param  OperationSite  $site
     * @return list<string>
     */
    private static function spellings(array $site): array
    {
        return array_values(array_filter(
            [$site['signature'], $site['operationId']],
            static fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Whether the node this site addresses is addressed by another site the scope did NOT match.
     *
     * @param  OperationSite  $site
     * @param  array<int, OperationSite>  $reaching
     * @param  array<int, true>  $matched
     */
    private static function sharedWithExcluded(array $site, array $reaching, array $matched): bool
    {
        foreach ($reaching as $index => $other) {
            if (! isset($matched[$index]) && $other['keys'] === $site['keys']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Says one thing once. A change is walked per rename, so a report about the CHANGE rather than about
     * the field comes out byte-identical every time; the second copy is noise, and noise is what trains
     * a reader to stop reading the channel.
     *
     * @param  array<string, true>  $said
     */
    private static function reportOnce(DocumentContext $context, Diagnostic $diagnostic, array &$said): void
    {
        $key = $diagnostic->code."\0".$diagnostic->message;

        if (isset($said[$key])) {
            return;
        }

        $said[$key] = true;
        $context->report($diagnostic);
    }

    /**
     * Gives one operation its own copy of the schema, renamed, leaving the shared component for
     * everybody else. Every `$ref` on the way down to the schema is expanded, because a copy still
     * pointing at the shared component would be the shared component.
     *
     * @param  array<string, mixed>  $doc
     * @param  OperationSite  $site
     * @param  array<string, bool>  $reaches
     * @param  array<string, true>  $said
     * @return array<string, mixed>
     */
    private function fork(array $doc, array $site, string $id, VersionVerb $verb, array $reaches, VersionChange $change, DocumentContext $context, array &$said): array
    {
        $operation = DocumentGraph::at($doc, $site['keys']);
        if (! is_array($operation)) {
            return $doc;
        }

        // As in {@see apply()}, and confined to this operation: the rest of the document goes on
        // publishing the shape the code publishes, and so do the rest of its examples.
        [$operation, $dropped] = $verb->rewriteOperationExamples($operation, $doc, $id, $site['keys'], $change);

        $outcome = VerbOutcome::Unresolved;
        $cyclic = false;
        $published = new PublishedSchemas($doc, $this->identity);
        $forked = $this->rewrite($operation, $published, $id, $verb, $reaches, [], $outcome, $cyclic);

        if ($cyclic) {
            // A schema that leads back to itself cannot be given a private copy: the copy would contain
            // the shared component again, and the operation would publish the older shape at one depth
            // and today's at the next. The head shape is at least a shape that exists. A schema written
            // that way is one route in; the other is a verb that PUTS a member back pointing at
            // something that leads here, which the expansion below meets on its way through the copy
            // and reports the same way, because it is the same fact.
            self::reportOnce($context, self::unforkable($change, sprintf(
                'a copy of the schema for %s would point back at the shared component, so the operation "%s" cannot be given one and was left at the shape the code publishes',
                PlainText::of($verb->schema()),
                PlainText::of($site['signature'] ?? implode('/', $site['keys'])),
            )), $said);

            return $doc;
        }

        if ($outcome !== VerbOutcome::Applied) {
            $this->reportOutcome($outcome, $verb, $change, $published, $context, $said);

            return $doc;
        }

        // Said only now: every refusal above leaves the document exactly as it was, examples included,
        // and a report of a drop that never happened is a defect the reader would go looking for.
        self::reportAll($context, $dropped, $said);
        $this->reportOutcome($outcome, $verb, $change, $published, $context, $said);

        // Everything the fork pulled in from `components` is a second node with the component's id on
        // it, and the copy says something different the moment it is renamed. `ContractIndex` resolves
        // an id to the shallowest, first-sorted node carrying it — `paths` before `components` — so the
        // copy would win the id and the component would vanish from the index it is still published in.
        return DocumentGraph::with($doc, $site['keys'], $this->reidentify($forked, DocumentGraph::identitiesIn($operation), self::forkScope($operation, $site)));
    }

    /**
     * Re-mints every identity in the forked node that was NOT already the operation's own — which is
     * exactly the set copied in from a component, whatever depth it came from.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<string, true>  $own
     * @return array<array-key, mixed>
     */
    private function reidentify(array $node, array $own, string $scope): array
    {
        $docuccino = $node['x-docuccino'] ?? null;
        $id = is_array($docuccino) ? $docuccino['id'] ?? null : null;

        if (is_array($docuccino) && is_string($id) && ! isset($own[$id])) {
            $forked = $this->identity->forkedId($id, $scope);

            if ($forked !== null) {
                $docuccino['id'] = $forked;
                $node['x-docuccino'] = $docuccino;
            }
        }

        foreach ($node as $key => $value) {
            if ($key !== 'x-docuccino' && is_array($value)) {
                $node[$key] = $this->reidentify($value, $own, $scope);
            }
        }

        return $node;
    }

    /**
     * What the copy belongs to, which is what keeps its id a function of the thing: the operation's own
     * identity where it has one, and the position it is published at where it does not.
     *
     * @param  array<array-key, mixed>  $operation
     * @param  OperationSite  $site
     */
    private static function forkScope(array $operation, array $site): string
    {
        $docuccino = $operation['x-docuccino'] ?? null;
        $id = is_array($docuccino) ? $docuccino['id'] ?? null : null;

        return is_string($id) ? $id : implode('/', $site['keys']);
    }

    /**
     * Walks every node, rewriting the ones carrying `$id`, and expanding on the way any `$ref` to a
     * component `$reaches` says leads to one — which is what gives a forked operation a private copy
     * instead of another pointer at the shared component. An EMPTY `$reaches` expands nothing, and that
     * is the whole of what an unscoped rename is: the same walk, in place.
     *
     * `$outcome` is the strongest thing {@see VerbOutcome} saw, so several copies of one schema report
     * one answer and a document that publishes it nowhere reports that instead. `$cyclic` says the
     * expansion met a component that contains itself, which is a copy that cannot be written; it can
     * only be set where something expands.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<string, bool>  $reaches
     * @param  list<string>  $visited
     * @return array<array-key, mixed>
     */
    private function rewrite(array $node, PublishedSchemas $published, string $id, VersionVerb $verb, array $reaches, array $visited, VerbOutcome &$outcome, bool &$cyclic): array
    {
        $ref = DocumentGraph::componentRef($node);
        if ($ref !== null && ($reaches[$ref] ?? false)) {
            if (in_array($ref, $visited, true)) {
                $cyclic = true;

                return $node;
            }

            $body = $published->body($ref);
            if ($body === null) {
                return $node;
            }

            $expanded = $this->rewrite($body, $published, $id, $verb, $reaches, [...$visited, $ref], $outcome, $cyclic);

            // OAS 3.1 lets a `$ref` carry siblings, and they annotate what they point at, so they win
            // over the body they are written beside.
            unset($node['$ref']);

            return [...$expanded, ...$node];
        }

        $docuccino = $node['x-docuccino'] ?? null;
        if (is_array($docuccino) && ($docuccino['id'] ?? null) === $id) {
            $node = $verb->apply($node, $published, $outcome);
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->rewrite($value, $published, $id, $verb, $reaches, $visited, $outcome, $cyclic);
            }
        }

        return $node;
    }

    /**
     * What a verb that did not apply has to say for itself. An applied one says nothing.
     *
     * @param  array<string, true>  $said
     */
    private function reportOutcome(VerbOutcome $outcome, VersionVerb $verb, VersionChange $change, PublishedSchemas $published, DocumentContext $context, array &$said): void
    {
        $diagnostic = $verb->diagnose($outcome, $change, $published);

        if ($diagnostic !== null) {
            self::reportOnce($context, $diagnostic, $said);
        }
    }

    /**
     * Every report a verb handed back, each said once. The examples a version could not be given come
     * this way: one per site rather than one per verb, because each is a different example at a
     * different pointer and a reader fixing one is not thereby told about the next.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @param  array<string, true>  $said
     */
    private static function reportAll(DocumentContext $context, array $diagnostics, array &$said): void
    {
        foreach ($diagnostics as $diagnostic) {
            self::reportOnce($context, $diagnostic, $said);
        }
    }

    /**
     * An operation the scope matched that cannot be given a private copy of the schema — the schema
     * contains itself, or the node it is published through is shared with operations the scope leaves
     * out. Its own code, because the remedy is the SCOPE rather than the declaration: nothing about the
     * change is written wrong, and telling the author to fix the declaration sends them to a line that
     * is already right.
     */
    private static function unforkable(VersionChange $change, string $problem): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.scope-unforkable',
            message: sprintf('%s could not be narrowed as written: %s.', PlainText::of($change->class), $problem),
            help: 'Drop the #[AppliesTo], or widen it to every operation that publishes the schema, and the shared component is renamed in place instead.',
        );
    }

    /**
     * A selector naming no operation this document publishes the schema for. Worth a warning because a
     * scope that matches nothing is indistinguishable from a change that was never declared: a route
     * renamed months later silently stops the change applying, and the version's document goes back to
     * saying what the code says without anything having been edited.
     */
    private static function matchesNothing(VersionChange $change, string $selector, VersionVerb $verb): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'versioning.scope-matches-nothing',
            message: sprintf(
                '%s is scoped to "%s", which names no operation this document publishes %s for, so that part of the change applies to nothing.',
                PlainText::of($change->class),
                PlainText::of($selector),
                PlainText::of($verb->schema()),
            ),
            help: 'Write the operation the way the document names it — `GET /api/things`, an operationId, or either with a `*` — and check the document publishes that schema for it.',
        );
    }

    /**
     * Declares the version header ONCE, in `components.parameters`, and points every operation the
     * document publishes at it: `in: header`, optional, defaulting to this document's version and
     * enumerating every version the application configures.
     *
     * Hoisted rather than restated per operation because the declaration is a function of the DOCUMENT
     * and of nothing else — the same name, description, enum and default on every one of them — while
     * its enum carries four parallel arrays one member long per version. Inline, a document grows by
     * operations × versions and a 400-operation API at 50 versions publishes 4.7 MB of one sentence
     * repeated; hoisted, it is flat in the version count. This is the componentization
     * {@see RepresentationPolicy}'s `enumComponents`/`errorComponents`/`paginationComponents` already
     * do for the repetition classes recovered from an application's own code.
     *
     * No `representation` keyword switches it off, and the three above are not the precedent for one:
     * each of them chooses between two shapes of a fact read out of the application, where the inline
     * form is a shape a consumer's toolchain may genuinely prefer. This parameter is minted whole from
     * document config, is byte-identical on every operation by construction, and — with each site's own
     * identity kept beside the `$ref` below — the inline form has no property the hoisted one lacks. A
     * switch here would choose only between a small document and a large one saying the same thing.
     *
     * The name is a function of the header name ({@see componentName()}), never of the route table, so
     * adding an unrelated route cannot move it. That is what lets this be NAMED at all where a scoped
     * change's fork may not be.
     *
     * The enum is derived from the document SET rather than from a second list kept beside it, so
     * adding a version moves the enum in every other version document. That is correct and deliberate —
     * versions are related by construction, so this is not the locality rule being broken.
     *
     * `webhooks` are left alone: a webhook is a request the SERVER makes, and the header is what a
     * CLIENT sends to pin a version.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<VersionChange>  $changes
     * @return array<string, mixed>
     */
    private function declareVersionHeader(array $doc, DocumentContext $context, string $version, array $changes, ?VersionOrder $order): array
    {
        $paths = $doc['paths'] ?? null;
        if (! is_array($paths)) {
            return $doc;
        }

        $name = $context->config->apiVersionHeader();
        $components = is_array($doc['components'] ?? null) ? Arr::stringKeyed($doc['components']) : [];
        $bucket = is_array($components[self::PARAMETERS] ?? null) ? Arr::stringKeyed($components[self::PARAMETERS]) : [];

        [$component, $collision] = self::componentName($name, array_keys($bucket));

        $declared = false;
        foreach ($paths as $path => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (PathItem::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (is_array($operation)) {
                    $item[$method] = $this->withVersionHeader($operation, $name, self::PARAMETER_REF.$component, $declared);
                }
            }

            $paths[$path] = $item;
        }

        // Nothing points at it when every operation documents the header itself, and a component
        // nothing reaches is bytes a consumer reads past on the way to the ones that mean something.
        // The collision goes unsaid with it: a name nothing was published under is nothing to act on.
        if (! $declared) {
            return $doc;
        }

        if ($collision !== null) {
            $context->report($collision);
        }

        $bucket[$component] = [
            // Minted from the document and the header rather than from any one route, which has no
            // business speaking for the others sharing it.
            'x-docuccino' => ['id' => $this->identity->publishedParameterId($context->documentId, 'header', $name)],
            'name' => $name,
            'in' => 'header',
            'description' => self::DESCRIPTION,
            'required' => false,
            'schema' => $this->versionSchema($context, $version, $changes, $order),
        ];

        $components[self::PARAMETERS] = $bucket;
        $doc['paths'] = $paths;
        $doc['components'] = $components;

        return $doc;
    }

    /**
     * What the one declaration is published under, and the report owed if it could not have the name it
     * asked for: the header name as a single word, which is the whole of the rule — `X-Api-Version` is
     * `XApiVersion` whatever the application routes. {@see ComponentNames} owns what happens when
     * something already holds that name, and holds it here for the same reason it does everywhere: a
     * first-come tail would reassign the name on a build that met the incumbent second.
     *
     * The diagnostic is handed back rather than reported, because whether it is worth saying depends on
     * something this cannot see — a document where nothing points at the component publishes no name to
     * have moved.
     *
     * @param  list<string>  $taken
     * @return array{string, Diagnostic|null}
     */
    private static function componentName(string $header, array $taken): array
    {
        $base = self::headerBase($header);

        [$names, $contested] = ComponentNames::mint(
            [self::CLAIM => ['base' => $base, 'identity' => null, 'content' => $header]],
            $taken,
        );

        $published = $names[self::CLAIM] ?? $base;

        if ($contested === []) {
            return [$published, null];
        }

        return [$published, new Diagnostic(
            severity: Severity::Warning,
            code: 'components.name-collision',
            message: sprintf(
                'A component in components.parameters already holds the name "%s", so the API version header was published under a name derived from its own instead (%s).',
                PlainText::of($base),
                PlainText::of($published),
            ),
            help: 'The component already holding the name was published before this ran and cannot move. Rename it, or name the header something else with api_version.header, and the version parameter publishes under a plain name again.',
        )];
    }

    /** The header name as one word: every run of letters and digits capitalised and joined. */
    private static function headerBase(string $header): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $header, -1, PREG_SPLIT_NO_EMPTY);
        $base = implode('', array_map(ucfirst(...), is_array($words) ? $words : []));

        // A header of nothing but punctuation is still a header the application configured, and the
        // component needs a name whatever it is called.
        return $base === '' ? 'ApiVersion' : $base;
    }

    /**
     * Points one operation at the shared declaration, unless the application documents the header
     * itself. The `$ref` carries the operation's OWN parameter identity, exactly as a hoisted error
     * response keeps its use site's: `x-docuccino` never reaches an emitted OpenAPI document, so the
     * artifact a consumer reads is a bare `$ref`, while the UIR, {@see ContractIndex} and per-operation
     * provenance keep an addressable node per operation and lose nothing to the hoist.
     *
     * @param  array<array-key, mixed>  $operation
     * @return array<array-key, mixed>
     */
    private function withVersionHeader(array $operation, string $name, string $ref, bool &$declared): array
    {
        $parameters = $operation['parameters'] ?? null;
        $parameters = is_array($parameters) ? array_values($parameters) : [];

        foreach ($parameters as $parameter) {
            if (! is_array($parameter) || ($parameter['in'] ?? null) !== 'header') {
                continue;
            }

            $stated = $parameter['name'] ?? null;

            // An application that documents the header itself keeps its own wording; two parameters of
            // one name in one location is a document no client can read.
            if (is_string($stated) && strcasecmp($stated, $name) === 0) {
                return $operation;
            }
        }

        $parameter = ['$ref' => $ref];

        $docuccino = $operation['x-docuccino'] ?? null;
        $operationId = is_array($docuccino) ? $docuccino['id'] ?? null : null;
        if (is_string($operationId)) {
            $parameter = ['x-docuccino' => ['id' => $this->identity->parameterId($operationId, 'header', $name)], ...$parameter];
        }

        $parameters[] = $parameter;
        $operation['parameters'] = $parameters;
        $declared = true;

        return $operation;
    }

    /**
     * The closed set of versions, decorated the way every other published enum is: SDK member names for
     * values no generator could name a constant after (`2026-09-01` is not an identifier), and the
     * change each version shipped as its per-value prose.
     *
     * Ordered by the order this document resolved rather than bytewise. An enum listing `1.10.0` before
     * `1.9.0` is the exact reading {@see VersionOrder} exists to replace, published in the artifact a
     * consumer reads.
     *
     * @param  list<VersionChange>  $changes
     * @return array<string, mixed>
     */
    private function versionSchema(DocumentContext $context, string $version, array $changes, ?VersionOrder $order): array
    {
        $versions = $this->documents->apiVersions();

        // An enum narrower than what the server accepts is worse than none: it marks a working request
        // invalid. This document's own version is one the server certainly answers, whatever the
        // configured set turned out to hold, so it is in the set or the set is wrong.
        if (! in_array($version, $versions, true)) {
            $versions[] = $version;
        }

        $versions = VersionOrder::sorted($versions, $order);

        $policy = RepresentationPolicy::fromConfig($context->config->representation);

        return EnumDecoration::apply(
            ['type' => 'string', 'enum' => $versions, 'default' => $version],
            $policy->enumNaming,
            self::versionNames($versions),
            self::changeProse($changes),
        );
    }

    /**
     * The SDK member name for each version. A version is not an identifier in any target language, and
     * the general list-value minting was written for sort keys (`-total` → `TotalDesc`), so every version
     * fell to its digit-prefix last resort and a generated client got `Version._20260901`. A version has
     * a spelling of its own — `V` and the separators as underscores — and the consumer should not be
     * paying for which minting the producer happened to reuse.
     *
     * @param  list<string>  $versions
     * @return list<string>
     */
    private static function versionNames(array $versions): array
    {
        $names = array_map(
            static fn (string $version): string => 'V'.trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $version), '_'),
            $versions,
        );

        // Two versions spelled differently can normalise alike — `1.10.0` and `1-10-0`. A generator
        // applies these by index and without a dedupe of its own, so a colliding set goes to the general
        // minting instead: uglier, and distinct, which is the half that has to hold.
        return count(array_unique($names)) === count($names) ? $names : ListValueNames::names($versions);
    }

    /**
     * What each version changed, keyed by the version it shipped in — the descriptions the changes
     * themselves carry, joined in the order the collector settled, so a version that shipped two
     * changes reads as one sentence per change and never depends on which file was met first.
     *
     * @param  list<VersionChange>  $changes
     * @return array<string, string>
     */
    private static function changeProse(array $changes): array
    {
        $prose = [];
        foreach ($changes as $change) {
            if ($change->description !== '') {
                $prose[$change->since][] = $change->description;
            }
        }

        return array_map(static fn (array $lines): string => implode(' ', $lines), $prose);
    }
}
