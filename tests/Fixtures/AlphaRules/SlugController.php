<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\AlphaRules;

use Illuminate\Http\JsonResponse;

/** Takes a {@see StoreSlugRequest}. */
final class SlugController
{
    public function store(StoreSlugRequest $request): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
