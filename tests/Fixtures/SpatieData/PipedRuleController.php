<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;

/** Takes the Data class whose rule strings are piped together as its request body. */
final class PipedRuleController
{
    public function store(PipedRuleData $data): JsonResponse
    {
        return new JsonResponse($data->toArray(), 201);
    }
}
