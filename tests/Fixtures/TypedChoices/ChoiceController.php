<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TypedChoices;

use Illuminate\Http\JsonResponse;

/** Takes a {@see StoreChoiceRequest}. */
final class ChoiceController
{
    public function store(StoreChoiceRequest $request): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
