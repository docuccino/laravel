<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ConditionalPresence;

use Illuminate\Http\JsonResponse;

/** Takes a {@see StoreProfileRequest}. */
final class ProfileController
{
    public function store(StoreProfileRequest $request): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
