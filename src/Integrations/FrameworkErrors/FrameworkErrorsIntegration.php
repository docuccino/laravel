<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FrameworkErrors;

/**
 * Entry point for the framework-defaults error tier (design §6, tier 2). Always on, since illuminate ships
 * everywhere: it documents Laravel's stock JSON error shapes for the exceptions the framework itself maps
 * to each status, so an app with no custom handler still gets its real error contract.
 * See {@see FrameworkErrorsExceptionToResponse} for where it sits in the chain.
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
