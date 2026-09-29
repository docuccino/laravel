<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RegexRules;

use Illuminate\Http\JsonResponse;

/** Takes a {@see StoreAddressRequest}. */
final class AddressController
{
    public function store(StoreAddressRequest $request): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
