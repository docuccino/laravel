<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ConditionalAcceptance;

use Illuminate\Http\JsonResponse;

/** Takes a {@see StoreConsentRequest}, and a {@see ConsentData} as a request body. */
final class ConsentController
{
    public function store(StoreConsentRequest $request): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    public function storeData(ConsentData $data): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
