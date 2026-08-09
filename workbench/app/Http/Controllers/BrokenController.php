<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * A controller whose route points at a method that does not exist — exercising the per-route
 * skeleton path (the action cannot be reflected, so a skeleton operation is emitted).
 */
final class BrokenController
{
    public function index(): JsonResponse
    {
        return response()->json([]);
    }
}
