<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;

/** Takes the Data class whose presence rules hold on a condition as its request body. */
final class ConditionalPresenceController
{
    public function store(ConditionalPresenceData $data): JsonResponse
    {
        return new JsonResponse($data->toArray(), 201);
    }
}
