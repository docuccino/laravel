<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A renderable domain exception: its own `render()` defines the app's REAL error contract, which the
 * inferred-handler tier documents (design §6). The golden build reflects `render()` and analyses it
 * through the stub engine — the body is inert (never dispatched).
 */
final class PaymentRequiredException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'type' => 'https://example.test/problems/payment-required',
            'title' => 'Payment Required',
            'status' => 402,
        ], 402);
    }
}
