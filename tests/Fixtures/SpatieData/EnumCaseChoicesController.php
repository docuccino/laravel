<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;

/** Takes the Data class whose choice attributes name enum cases as its request body. */
final class EnumCaseChoicesController
{
    public function store(EnumCaseChoicesData $data): JsonResponse
    {
        return new JsonResponse($data->toArray(), 201);
    }
}
