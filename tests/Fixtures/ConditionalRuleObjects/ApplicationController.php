<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ConditionalRuleObjects;

use Illuminate\Http\JsonResponse;

/** Takes a {@see StoreApplicationRequest}. */
final class ApplicationController
{
    public function store(StoreApplicationRequest $request): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
