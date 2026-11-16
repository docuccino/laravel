<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\FormatRules;

use Illuminate\Http\JsonResponse;

/** Takes a {@see StoreDeviceRequest}. */
final class DeviceController
{
    public function store(StoreDeviceRequest $request): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
