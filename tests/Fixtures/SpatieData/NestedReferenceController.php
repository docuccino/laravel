<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;

/** Takes the Data class whose nested properties name fields by reference as its request body. */
final class NestedReferenceController
{
    public function store(NestedReferenceData $data): JsonResponse
    {
        return new JsonResponse($data->toArray(), 201);
    }
}
