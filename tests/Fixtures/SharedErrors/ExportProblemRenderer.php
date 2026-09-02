<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * One arm, one problem document, and four members it can only ask the failure for. The words it writes out
 * fold; the enum, the instant, the flag and the extension bag do not, so the example beside the schema has
 * to be filled at every member the schema itself is the only thing that describes.
 */
final class ExportProblemRenderer
{
    public function __invoke(Throwable $e): ?JsonResponse
    {
        if (! $e instanceof ExportRefusedException) {
            return null;
        }

        return (new ExportProblem(
            type: 'https://example.com/problems/export-refused',
            title: 'Conflict',
            status: 409,
            reason: $e->reason(),
            failedAt: $e->failedAt(),
            retryable: $e->retryable(),
            context: $e->context(),
        ))->toProblemResponse();
    }
}
