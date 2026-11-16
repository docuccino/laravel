<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ExclusionRules;

use Illuminate\Http\JsonResponse;

/** Takes a {@see StoreAccountRequest}. */
final class AccountController
{
    public function store(StoreAccountRequest $request): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
