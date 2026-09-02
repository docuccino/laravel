<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * One arm, one problem document, and one member it can only ask the failure for. The words it writes out
 * fold; the origin does not, so the example beside the schema has to be filled at the member whose only
 * description is that schema.
 */
final class BatchProblemRenderer
{
    public function __invoke(Throwable $e): ?JsonResponse
    {
        if (! $e instanceof BatchRefusedException) {
            return null;
        }

        return (new BatchProblem(
            type: 'https://example.com/problems/batch-refused',
            title: 'Unprocessable Content',
            status: 422,
            origin: $e->origin(),
        ))->toProblemResponse();
    }
}
