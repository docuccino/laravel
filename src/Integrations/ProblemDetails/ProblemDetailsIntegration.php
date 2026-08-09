<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ProblemDetails;

/**
 * Entry point for the RFC 9457 Problem Details preset (design §6 chain). The mapper is registered
 * unconditionally into the resolved chain; it self-gates on the per-document
 * `error_responses => 'problem-details'` config, so it stays inert for documents that did not opt in
 * (extensions resolve once per build across all documents, not per document).
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
