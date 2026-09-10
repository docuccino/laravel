<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\DocumentGraph;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\Glob;

/**
 * Turns the document a build just assembled into the document for the API version it declares: every
 * declared change that shipped AFTER this version is applied in REVERSE, and then every operation is
 * given the header a client pins a version with ({@see ApiVersionHeader}). A document with no
 * `api_version` is not an API version, and this moves not a byte of it.
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
    public function __construct(
        private VersionChangeCollector $changes,
        private ApiVersionHeader $header = new ApiVersionHeader,
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
                    $config->key,
                ),
                help: sprintf(
                    'Set documents.%s.info.version to the version this document describes — that value IS the API version.',
                    $config->key,
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
                if ($verb instanceof OperationVerb) {
                    $doc = $this->applyToOperations($doc, $verb, $change, $context, $said);

                    continue;
                }

                $doc = $change->selectors === []
                    ? $this->apply($doc, $verb, $change, $context, $said)
                    : $this->applyScoped($doc, $verb, $change, $context, $said);
            }
        }

        $document->replace($this->header->declareIn($doc, $context, $version, $set->changes, $set->order));
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
                self::reportOnce($context, VerbDiagnostics::scopeMatchesNothing($change, $selector, $verb), $said);
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
                self::reportOnce($context, VerbDiagnostics::unforkable($change, sprintf(
                    'the operation "%s" is published through a path item it shares with operations the scope leaves out, so it cannot be given a copy of the schema for %s and was left at the shape the code publishes',
                    $reaching[$index]['signature'] ?? implode('/', $reaching[$index]['keys']),
                    $verb->schema(),
                )), $said);

                continue;
            }

            $doc = $this->fork($doc, $reaching[$index], $id, $verb, $reaches, $change, $context, $said);
        }

        return $doc;
    }

    /**
     * Applies a verb whose subject is the OPERATION rather than a schema, on every operation the change
     * is in scope for — {@see OperationVerb} states why that is the whole of the scope rule here, and
     * why the fork the schema path performs has no analogue.
     *
     * Every operation this document publishes is visited where no `#[AppliesTo]` is written, and only
     * the ones a selector names where one is. There is no widening to refuse: an unscoped verb rewrites
     * every operation that declares the parameter and a scoped one a subset of them, so a scope that
     * decides nothing leaves the document alone and says which of the two things is wrong.
     *
     * The one thing a scope cannot narrow is a path item two paths address through a `$ref`, because
     * both operations ARE one node — renaming its parameter would rename it for the path the scope
     * excluded. Refused for the same reason {@see fork()} refuses to write a private copy there.
     *
     * That shared node is also why the walk is per NODE rather than per site. One node is one
     * declaration and one edit, so it owes one report: two sites over it would otherwise be asked
     * twice and name TWO operations for a single refusal the author fixes once. (The second pass would
     * edit nothing either way — a parameter already carrying the older name is no longer the one the
     * verb looks for — so the dedupe is about what is reported rather than about what is written.)
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, true>  $said
     * @return array<string, mixed>
     */
    private function applyToOperations(array $doc, OperationVerb $verb, VersionChange $change, DocumentContext $context, array &$said): array
    {
        $sites = DocumentGraph::operationSites($doc);
        $selectors = $change->selectors;

        $matched = [];
        foreach ($sites as $index => $site) {
            if ($selectors === [] || self::names($change, $site)) {
                $matched[$index] = true;
            }
        }

        foreach ($selectors as $selector) {
            if (! self::namesAny([$selector], $sites)) {
                self::reportOnce($context, VerbDiagnostics::scopeNamesNoOperation($change, $selector, $verb), $said);
            }
        }

        if ($matched === []) {
            return $doc;
        }

        $written = [];
        $applied = false;

        /** @var list<string> $refused */
        $refused = [];

        // Whether any operation was actually looked at. A run where every matched one was refused above
        // has said why already, and "no operation declares that parameter" on top of it would be a
        // second problem the reader would go looking for — of a parameter the document plainly declares.
        $walked = false;

        foreach (array_keys($matched) as $index) {
            $site = $sites[$index];
            $node = implode("\0", $site['keys']);
            if (isset($written[$node])) {
                continue;
            }
            $written[$node] = true;

            if (self::sharedWithExcluded($site, $sites, $matched)) {
                self::reportOnce($context, VerbDiagnostics::unnarrowable($change, sprintf(
                    'the operation "%s" is published through a path item it shares with operations the scope leaves out, so %s cannot be renamed for it alone and was left at the name the code gives it',
                    $site['signature'] ?? implode('/', $site['keys']),
                    $verb->declares(),
                )), $said);

                continue;
            }

            $operation = DocumentGraph::at($doc, $site['keys']);
            if (! is_array($operation)) {
                continue;
            }

            $walked = true;

            // One outcome PER OPERATION, and this is the half {@see VerbOutcome::strongest()} must not
            // be asked for here. A schema is published more than once and the copies are one node, so
            // the strongest answer over them is the answer; two operations are two declarations, and
            // collapsing them lets a refusal on one hide under an edit on another — a version document
            // that spells one logical parameter two ways, with nothing said.
            $outcome = VerbOutcome::Absent;
            $edited = $verb->apply($operation, self::nodeScope($operation, $site), $this->identity, $outcome);

            if ($edited !== $operation) {
                $doc = DocumentGraph::with($doc, $site['keys'], $edited);
            }

            if ($outcome === VerbOutcome::Applied) {
                $applied = true;
            }

            if ($outcome === VerbOutcome::Declined) {
                $refused[] = $site['signature'] ?? implode('/', $site['keys']);
            }
        }

        foreach ($refused as $operation) {
            self::reportOnce($context, $verb->refused($operation, $change), $said);
        }

        // Said only where the whole walk came to nothing. Most operations in scope will not declare the
        // parameter — an unscoped verb visits every operation the document publishes — so "no operation
        // declares it" is the walk's answer rather than any one operation's, and a run that edited or
        // refused something has already said what it found.
        if ($walked && ! $applied && $refused === []) {
            self::reportOnce($context, $verb->unreached($change), $said);
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
            self::reportOnce($context, VerbDiagnostics::unforkable($change, sprintf(
                'a copy of the schema for %s would point back at the shared component, so the operation "%s" cannot be given one and was left at the shape the code publishes',
                $verb->schema(),
                $site['signature'] ?? implode('/', $site['keys']),
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
        return DocumentGraph::with($doc, $site['keys'], $this->reidentify($forked, DocumentGraph::identitiesIn($operation), self::nodeScope($operation, $site)));
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
     * What a node inside this operation belongs to, which is what keeps its id a function of the thing:
     * the operation's own identity where it has one, and the position it is published at where it does
     * not. Asked by the fork, which re-mints every id it copied in, and by a verb that moves a name an
     * id was derived from — nothing forks on that second path.
     *
     * @param  array<array-key, mixed>  $operation
     * @param  OperationSite  $site
     */
    private static function nodeScope(array $operation, array $site): string
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
}
