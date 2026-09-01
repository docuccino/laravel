<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\Data;

/**
 * A Data class whose two statuses are genuinely both reachable on one endpoint: the guard is state the
 * build cannot see, so the honest answer is to publish the body under each. The counter-case to
 * {@see RouteStatusData}, and the reason narrowing is a recognised shape rather than a default.
 */
final class GuardedStatusData extends Data
{
    public function __construct(
        public string $id,
        public bool $justCreated = false,
    ) {}

    protected function calculateResponseStatus(Request $request): int
    {
        if ($this->justCreated) {
            return Response::HTTP_CREATED;
        }

        return Response::HTTP_OK;
    }
}
