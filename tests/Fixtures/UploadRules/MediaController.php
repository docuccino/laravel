<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\UploadRules;

use Illuminate\Http\JsonResponse;

/** Takes a {@see StoreMediaRequest}. */
final class MediaController
{
    public function store(StoreMediaRequest $request): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
