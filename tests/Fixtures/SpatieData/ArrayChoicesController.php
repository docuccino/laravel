<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;

/** Takes the Data classes whose array-typed properties carry choice attributes as request bodies. */
final class ArrayChoicesController
{
    public function store(ArrayChoicesData $data): JsonResponse
    {
        return new JsonResponse($data->toArray(), 201);
    }

    public function merge(MergedArrayChoicesData $data): JsonResponse
    {
        return new JsonResponse($data->toArray(), 201);
    }
}
