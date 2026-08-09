<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FrameworkErrors;

/**
 * Entry point for the framework-defaults error tier (design §6 error-response chain, tier 2).
 * Always on (illuminate ships everywhere): documents Laravel's stock JSON error shapes — the
 * `{message, errors}` a 422 renders, the `{message}` a 401/403/404 renders — for the exceptions
 * the framework itself maps to those statuses, so an app that has NOT installed a preset or written
 * a custom handler still gets its real error contract documented.
 *
 * Ordered AFTER the inferred-handler tier and any active preset, and BEFORE the terminal generic
 * fallback (DefaultExceptionToResponse) — so a real handler or a preset always wins, and this tier
 * only fills the framework exceptions neither covered.
 */
final class FrameworkErrorsIntegration
{
    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            FrameworkErrorsExceptionToResponse::class,
        ];
    }
}
