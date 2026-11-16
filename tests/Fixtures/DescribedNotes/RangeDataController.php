<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DescribedNotes;

use Illuminate\Http\JsonResponse;

/** Takes {@see RangeData} as query parameters and as a body. */
final class RangeDataController
{
    public function index(RangeData $data): JsonResponse
    {
        return new JsonResponse([]);
    }

    public function store(RangeData $data): JsonResponse
    {
        return new JsonResponse([], 201);
    }
}
