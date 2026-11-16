<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;

/** Takes the Data class whose attributes name rules other than their class names as its request body. */
final class AttributeKeywordController
{
    public function store(AttributeKeywordData $data): JsonResponse
    {
        return new JsonResponse($data->toArray(), 201);
    }
}
