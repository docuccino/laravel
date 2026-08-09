<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Config\ConfigPaths;

/**
 * Config-shape info diagnostics for a document (design §9): surface the two silent no-ops the config
 * surface used to swallow, so a misconfiguration is discoverable instead of mystifying.
 *
 * - An `enabled` switch on one of the always-on producers (validation / form_request /
 *   framework_errors / problem_details / inferred_handler) — these have no {@see IntegrationToggles}
 *   entry, so the switch is silently ignored; the diagnostic says so (problem_details is driven by
 *   the `error_responses` preset, not an `enabled` toggle).
 * - An unknown `tags.default_strategy` value — {@see DocumentConfig::tagDefaultStrategy()} coerces it
 *   to `controller`, and the diagnostic now names the coercion instead of applying it silently.
 * - A path-like key pointing OUTSIDE the app base path. {@see ConfigPaths} relativises every in-app
 *   path so it cannot fold this machine's filesystem layout into the emitted `configHash`; a path that
 *   genuinely lives elsewhere has to be kept verbatim, which DOES make the document machine-dependent
 *   — so the diagnostic names the key and the path instead of letting committed bytes drift silently.
 *
 * @internal
 */
final class ConfigDiagnostics
{
    /**
     * The always-on producers with no `enabled` toggle (the 5 producers absent from
     * {@see IntegrationToggles}), in a fixed order for deterministic diagnostics.
     */
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
}
