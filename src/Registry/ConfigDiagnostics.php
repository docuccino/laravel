<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\PlainText;
use Docuccino\Laravel\Config\ConfigPaths;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderConfig;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;

/**
 * Config-shape info diagnostics (design §9) — the misconfigurations that would otherwise be silent
 * no-ops:
 *
 * - An `enabled` switch on an always-on producer. Those have no {@see IntegrationToggles} entry, so the
 *   switch does nothing.
 * - An `integrations.<key>` bag naming neither a toggle nor an always-on producer — a typo, so the
 *   whole bag under it is read by nobody.
 * - An unknown `tags.default_strategy`, which {@see DocumentConfig::tagDefaultStrategy()} coerces to
 *   `controller`.
 * - An `error_responses` value outside the two the key accepts, which reads as `default`. Every other
 *   value the build could be handed here would otherwise change what a document says about every error
 *   in it without a word — null included, which is what an unset `env()` puts under a key an author
 *   deliberately wrote. Only an ABSENT key is silent, and it resolves to `none` rather than to this.
 * - An `integrations.query_builder.filter_descriptions` key naming no filter kind. The sentence under it
 *   can never be reached, so the override looks like it did nothing.
 * - A `representation.examples.formats` sample that is not a string. `format` is a string keyword, so
 *   nothing could publish it; the same code covers a sample a field's own rules reject, which only the
 *   build can find out.
 * - A `tags.definitions` `parent` that {@see DocumentConfig::tagDefinitions()} dropped, because it
 *   names no defined tag or would close a cycle — OAS 3.2 allows neither.
 * - A path-like key pointing outside the app base path. {@see ConfigPaths} can't relativise it, so it
 *   goes verbatim into the `configHash` and the output becomes machine-dependent.
 * - A path-like key holding a NUL byte, which no filesystem call accepts. Every reader is handed
 *   nothing instead ({@see ConfigPaths::unholdable()}), so this is what says the path was dropped.
 *
 * @internal
 */
final class ConfigDiagnostics
{
    /** Producers with no `enabled` toggle, i.e. absent from {@see IntegrationToggles}. Fixed order. */
    private const ALWAYS_ON = ['validation', 'form_request', 'framework_errors', 'inferred_handler'];

    private const VALID_TAG_STRATEGIES = ['controller', 'none'];

    /** Everything `error_responses` accepts. Anything else is read as the first of them. */
    private const VALID_ERROR_RESPONSES = ['default', 'none'];

    /**
     * @return list<Diagnostic>
     */
    public static function for(DocumentConfig $document): array
    {
        $diagnostics = [];

        foreach (self::ALWAYS_ON as $key) {
            if (array_key_exists('enabled', $document->integration($key))) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Info,
                    code: 'config.enabled-ignored',
                    message: sprintf(
                        'integrations.%s.enabled is set, but %s is an always-on producer with no enable/disable toggle — the switch is ignored.',
                        $key,
                        $key,
                    ),
                );
            }
        }

        foreach (self::unknownIntegrations($document) as $key) {
            // Info, like its neighbours here: nothing is wrong with the document that got built, and
            // this also fires on every viewer request and cache warm, where a louder severity would be
            // noise. The switch it names is still the one thing that would change the output.
            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'config.unknown-integration',
                message: sprintf(
                    'integrations.%s names no integration — nothing reads that bag, so its settings do nothing.',
                    $key,
                ),
                help: self::integrationHelp($key),
            );
        }

        // Asked by presence: a key holding null is an author who named it, and only an absent key is silent.
        if (array_key_exists('error_responses', $document->raw)) {
            $configured = $document->raw['error_responses'];

            if (! in_array($configured, self::VALID_ERROR_RESPONSES, true)) {
                // A WARNING, like the other two here that drop something the author wrote rather than
                // merely ignoring a switch: what this key names is the whole document's error contract,
                // so a value read as something else changes the body of every error response in it.
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'config.unknown-error-responses',
                    message: sprintf(
                        "error_responses is %s, which names no error-response strategy — the document is built as if it said 'default'.",
                        is_string($configured) ? "'".PlainText::of($configured)."'" : get_debug_type($configured),
                    ),
                    help: "Valid values are 'default' (the framework's own error shapes, and the implicit "
                        ."responses) and 'none'. The shape your own exception handling returns is read from "
                        .'your code either way, and is published over both.',
                );
            }
        }

        $strategy = $document->tags['default_strategy'] ?? null;
        if (is_string($strategy) && ! in_array($strategy, self::VALID_TAG_STRATEGIES, true)) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'config.unknown-tag-strategy',
                message: sprintf(
                    "Unknown tags.default_strategy '%s' — falling back to 'controller' (valid values: controller, none).",
                    $strategy,
                ),
            );
        }

        foreach ($document->tagParentIssues() as $issue) {
            $diagnostics[] = $issue['cycle']
                ? new Diagnostic(
                    severity: Severity::Info,
                    code: 'config.tag-parent-cycle',
                    message: sprintf(
                        "tags.definitions: '%s' is parented to '%s', which closes a cycle — the link is dropped so the tag hierarchy stays a tree.",
                        $issue['tag'],
                        $issue['parent'],
                    ),
                )
                : new Diagnostic(
                    severity: Severity::Info,
                    code: 'config.unknown-tag-parent',
                    message: sprintf(
                        "tags.definitions: '%s' is parented to '%s', which no definition declares — the link is dropped (OAS 3.2 requires a parent tag to exist).",
                        $issue['tag'],
                        $issue['parent'],
                    ),
                );
        }

        foreach (self::unknownFilterKinds($document) as $kind) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'config.unknown-filter-kind',
                message: sprintf(
                    "integrations.query_builder.filter_descriptions names filter kind '%s', which no Query Builder filter has — the sentence under it is never used.",
                    $kind,
                ),
                help: sprintf('Filter kinds are: %s.', implode(', ', QueryBuilderParameters::filterKinds())),
            );
        }

        foreach (self::unpublishableFormatSamples($document) as $format => $type) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'config.format-sample-rejected',
                message: sprintf(
                    'The example configured for format "%s" is %s rather than a string, and `format` only ever constrains a string, so nothing can publish it — the format is illustrated as if it had never been configured.',
                    $format,
                    $type,
                ),
                help: sprintf('Set representation.examples.formats.%s to a string, or drop the key.', $format),
            );
        }

        foreach (ConfigPaths::unholdable($document->raw) as $rejected) {
            // A WARNING where its neighbours are info: the build did not merely ignore a switch, it
            // dropped a path the author configured — an overlay is not applied, a content tree is not
            // read — and the alternative for the same input was a `ValueError` out of `glob()` naming no
            // config key at all. It cannot fire on anything but a value someone typed.
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'config.path-rejected',
                message: sprintf(
                    '%s contains a NUL byte, which no filesystem path can hold, so nothing read it — %s.',
                    $rejected['key'],
                    PlainText::of($rejected['path']),
                ),
                help: 'Write the path in single quotes, or escape the backslash — "\0" in a double-quoted PHP string is a NUL byte, not the two characters it looks like.',
            );
        }

        foreach (ConfigPaths::machineDependent($document->raw) as $outside) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'config.machine-dependent-path',
                message: sprintf(
                    "%s points outside the application base path ('%s') — it is kept as configured, so it is folded verbatim into this document's configHash and the output becomes machine-dependent. Move the target inside the application (any in-app path is stored base-path-relative) to keep the emitted bytes portable.",
                    $outside['key'],
                    $outside['path'],
                ),
            );
        }

        return $diagnostics;
    }

    /**
     * The `integrations.query_builder.filter_descriptions` keys naming no filter kind, in config order.
     * Non-string sentences are dropped by {@see QueryBuilderConfig::withFilterDescriptions()} and are not
     * reported here — the key itself is the actionable half.
     *
     * @return list<string>
     */
    private static function unknownFilterKinds(DocumentConfig $document): array
    {
        $kinds = QueryBuilderParameters::filterKinds();
        $unknown = [];

        foreach (array_keys(Hydrate::map($document->integration('query_builder')['filter_descriptions'] ?? null)) as $kind) {
            if (! in_array((string) $kind, $kinds, true)) {
                $unknown[] = (string) $kind;
            }
        }

        return $unknown;
    }

    /**
     * The `representation.examples.formats` entries that could never reach a document, as format => the
     * type that was written there. {@see RepresentationPolicy::fromConfig()} drops these, and this is what
     * says so; a string sample that a particular field's rules reject is reported by the build, under the
     * same code, because only the build knows the field.
     *
     * @return array<string, string>
     */
    private static function unpublishableFormatSamples(DocumentConfig $document): array
    {
        $examples = Hydrate::map($document->representation['examples'] ?? null);

        $unpublishable = [];
        foreach (Hydrate::map($examples['formats'] ?? null) as $format => $sample) {
            if (! is_string($sample)) {
                $unpublishable[(string) $format] = get_debug_type($sample);
            }
        }

        return $unpublishable;
    }

    /**
     * The `integrations.*` keys nothing reads, in config order.
     *
     * @return list<string>
     */
    private static function unknownIntegrations(DocumentConfig $document): array
    {
        $known = self::knownIntegrations();
        $unknown = [];

        foreach (array_keys(Hydrate::map($document->raw['integrations'] ?? null)) as $key) {
            if (! in_array((string) $key, $known, true)) {
                $unknown[] = (string) $key;
            }
        }

        return $unknown;
    }

    /**
     * Every key an `integrations` bag can carry: the toggle table plus the always-on producers.
     *
     * @return list<string>
     */
    private static function knownIntegrations(): array
    {
        return [...array_keys(IntegrationToggles::descriptors()), ...self::ALWAYS_ON];
    }

    /** A near miss is almost always a typo, so name the one key they meant rather than all sixteen. */
    private static function integrationHelp(string $key): string
    {
        $nearest = self::nearest($key);

        return $nearest !== null
            ? sprintf('Did you mean integrations.%s?', $nearest)
            : sprintf('Valid keys are: %s.', implode(', ', self::knownIntegrations()));
    }

    /** The closest known key within a few edits, or null when the name is nothing like any of them. */
    private static function nearest(string $key): ?string
    {
        // levenshtein() is byte-wise and cheap; a key long enough to make that a bad idea is not a typo.
        if (strlen($key) > 64) {
            return null;
        }

        $best = null;
        $distance = 4;

        foreach (self::knownIntegrations() as $known) {
            $candidate = levenshtein(strtolower($key), $known);
            if ($candidate < $distance) {
                $best = $known;
                $distance = $candidate;
            }
        }

        return $best;
    }
}
