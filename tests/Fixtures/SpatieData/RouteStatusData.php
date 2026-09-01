<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\Data;

/**
 * A Data class whose `calculateResponseStatus()` override picks between two constant statuses on the
 * route's NAME — the shape that publishes one body under both 200 and 201 on every operation returning
 * it, a GET included, until the route settles the choice. The override is real (reflected, and its body
 * walked as written by a scripted trace); only the folded return type is scripted.
 */
final class RouteStatusData extends Data
{
    public function __construct(
        public string $id,
    ) {}

    protected function calculateResponseStatus(Request $request): int
    {
        return $request->routeIs('*things.store') ? Response::HTTP_CREATED : Response::HTTP_OK;
    }
}
