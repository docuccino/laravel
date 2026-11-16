<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RuleBuilders;

use Illuminate\Http\JsonResponse;

/** Takes a {@see StoreOrderRequest}. */
final class OrderController
{
    public function store(StoreOrderRequest $request): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
