<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Description;
use Illuminate\Http\JsonResponse;
use Workbench\App\Http\Requests\StoreWidgetRequest;

/**
 * One `#[Description(request: true)]` covering every action on the controller. The actions that
 * document a body take it; the one that documents none has nothing to fix at it, since the
 * declaration is not written there.
 */
#[Description(text: 'Send the whole widget; every action here replaces rather than merges.', request: true)]
final class SharedBodyProseController
{
    public function stored(StoreWidgetRequest $request): JsonResponse
    {
        return response()->json([], 201);
    }

    public function bodyless(): JsonResponse
    {
        return response()->json([]);
    }
}
