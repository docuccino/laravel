<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workbench\App\Enums\TokenRefusal;

/**
 * Refusals whose status is read off the reason's enum case: written at the response, restated by a chain,
 * and chosen at run time between cases that answer differently.
 */
final class TokenController
{
    /** Refuse an expired token. */
    public function expire(): JsonResponse
    {
        return response()->json(['error' => 'expired'], TokenRefusal::Expired->status());
    }

    /** Refuse a token that was already rotated. */
    public function rotate(): JsonResponse
    {
        return response()->json(['error' => 'rotated'])->setStatusCode(TokenRefusal::AlreadyRotated->httpStatus());
    }

    /** Refuse a token for the reason the request names. */
    public function refuse(Request $request): JsonResponse
    {
        $reason = $request->boolean('rotated') ? TokenRefusal::AlreadyRotated : TokenRefusal::Expired;

        return response()->json(['error' => $reason->value], $reason->status());
    }
}
