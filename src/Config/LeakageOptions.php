<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

use Docuccino\Core\Lint\SensitiveFieldLintOptions;

/**
 * `docuccino.lint.leakage` as the core options object.
 *
 * One reader, because three things now depend on the same answer: the leakage lint, the redaction the
 * response recorder applies on the way out, and the re-check the build runs over a committed
 * recording. An application that taught Docuccino its own sensitive member names has taught all three.
 *
 * The three read the same options and honour different parts of them on purpose: `enabled` and the
 * name half of `allow` turn a REPORT off, and neither is a request to publish a credential — the
 * recorder's redaction honours a pointer alone.
 *
 * @internal
 */
final class LeakageOptions
{
    /**
     * @param  array<string, mixed>  $leakage
     * @param  bool  $honourSwitch  whether `enabled: false` turns the rule off. It does for the lint,
     *                              which is a report; it never does for redaction, because switching a
     *                              report off is not a request to publish credentials.
     */
    public static function fromConfig(array $leakage, bool $honourSwitch = true): SensitiveFieldLintOptions
    {
        $allow = is_array($leakage['allow'] ?? null)
            ? array_values(array_filter($leakage['allow'], 'is_string'))
            : [];

        $options = new SensitiveFieldLintOptions(
            enabled: ! $honourSwitch || ($leakage['enabled'] ?? true) !== false,
            allow: $allow,
        );

        // Extra token → label heuristics merge over the defaults; existing tokens keep their label.
        $patterns = [];
        foreach (is_array($leakage['patterns'] ?? null) ? $leakage['patterns'] : [] as $token => $label) {
            if (is_string($token) && is_string($label)) {
                $patterns[$token] = $label;
            }
        }

        return $patterns === [] ? $options : $options->withPatterns($patterns);
    }
}
