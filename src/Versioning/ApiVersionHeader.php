<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Versioning;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Versioning\VersionOrder;
use Docuccino\Laravel\Config\ConfiguredDocuments;
use Docuccino\Laravel\Support\ListValueNames;

/**
 * The `X-Api-Version` header a client pins a version with. {@see declareIn()} is the whole of the job,
 * and states why it is hoisted rather than restated per operation.
 *
 * Nothing here reads a version change — {@see ApiVersionTransformer} applies those and then asks for
 * this, which is the whole of the relationship between the two.
 *
 * @internal
 */
final readonly class ApiVersionHeader
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
        private ConfiguredDocuments $documents = new ConfiguredDocuments,
        private IdentityGenerator $identity = new IdentityGenerator,
    ) {}

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
    public function declareIn(array $doc, DocumentContext $context, string $version, array $changes, ?VersionOrder $order): array
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
                $base,
                $published,
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
