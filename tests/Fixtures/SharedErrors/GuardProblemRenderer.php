<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SharedErrors;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * The commonest error renderer there is: one problem document, several reasons for it. Each arm builds the
 * same {@see GuardProblem} at the same status under the same media type, and differs only in the words it
 * fills in — which is the difference between illustrations, not between contracts.
 *
 * The document is CONSTRUCTED, one hop from the response, so what each arm carries is the set of
 * constructor arguments that arm passed — the fact an example is built from.
 */
final class GuardProblemRenderer
{
    public function __invoke(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof TokenExpiredException => $this->problem('Token expired', 'Refresh the token and retry.'),
            $e instanceof RoleMissingException => $this->problem('Role missing', 'Ask an administrator for access.'),
            $e instanceof RegionBlockedException => $this->problem('Region blocked', 'This endpoint is not served in your region.'),
            default => null,
        };
    }

    private function problem(string $title, string $detail): JsonResponse
    {
        return (new GuardProblem(
            type: 'about:blank',
            title: $title,
            status: 403,
            detail: $detail,
        ))->toProblemResponse();
    }
}
