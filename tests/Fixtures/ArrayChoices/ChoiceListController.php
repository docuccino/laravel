<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ArrayChoices;

use Illuminate\Http\JsonResponse;

/** Takes a {@see StoreChoiceListRequest}. */
final class ChoiceListController
{
    public function store(StoreChoiceListRequest $request): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
