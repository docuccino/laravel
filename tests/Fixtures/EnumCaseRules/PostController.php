<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\EnumCaseRules;

use Illuminate\Http\JsonResponse;

/** Takes a {@see StorePostRequest}. */
final class PostController
{
    public function store(StorePostRequest $request): JsonResponse
    {
        return response()->json([], 201);
    }
}
