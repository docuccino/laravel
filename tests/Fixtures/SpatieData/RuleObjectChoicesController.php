<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\JsonResponse;

/** Takes the Data class whose attributes hand over framework rule objects as its request body. */
final class RuleObjectChoicesController
{
    public function store(RuleObjectChoicesData $data): JsonResponse
    {
        return new JsonResponse($data->toArray(), 201);
    }
}
