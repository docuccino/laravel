<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ProblemDetails;

/**
 * Entry point for the RFC 9457 Problem Details preset (design §6). The mapper registers unconditionally and
 * self-gates on the per-document `error_responses => 'problem-details'`, because extensions resolve once per
 * build across all documents rather than per document.
 */
final class ProblemDetailsIntegration
{
    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            ProblemDetailsExceptionToResponse::class,
        ];
    }
}
