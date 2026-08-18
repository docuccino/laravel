<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Laravel\Config\ConfigPaths;

/**
 * Config-shape info diagnostics (design §9) — the misconfigurations that would otherwise be silent
 * no-ops:
 *
 * - An `enabled` switch on an always-on producer. Those have no {@see IntegrationToggles} entry, so the
 *   switch does nothing (problem_details is driven by the `error_responses` preset instead).
 * - An `integrations.<key>` bag naming neither a toggle nor an always-on producer — a typo, so the
 *   whole bag under it is read by nobody.
 * - An unknown `tags.default_strategy`, which {@see DocumentConfig::tagDefaultStrategy()} coerces to
 *   `controller`.
 * - A `tags.definitions` `parent` that {@see DocumentConfig::tagDefinitions()} dropped, because it
 *   names no defined tag or would close a cycle — OAS 3.2 allows neither.
 * - A path-like key pointing outside the app base path. {@see ConfigPaths} can't relativise it, so it
 *   goes verbatim into the `configHash` and the output becomes machine-dependent.
 *
 * @internal
 */
final class ConfigDiagnostics
{
    /** Producers with no `enabled` toggle, i.e. absent from {@see IntegrationToggles}. Fixed order. */
    private const ALWAYS_ON = ['validation', 'form_request', 'framework_errors', 'problem_details', 'inferred_handler'];

    private const VALID_TAG_STRATEGIES = ['controller', 'none'];

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
