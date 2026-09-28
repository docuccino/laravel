<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\IgnoreParam;
use Docuccino\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;

/**
 * Actions reached from a signed link — an unsubscribe or a download — whose signature the route's
 * middleware checks before the action runs.
 */
final class SignedLinkController
{
    /**
     * Confirm a subscription.
     */
    #[Group('Links')]
    public function confirm(string $token): JsonResponse
    {
        return response()->json(['confirmed' => true]);
    }

    /**
     * Confirm a subscription from a link that always expires.
     */
    #[Group('Links')]
    #[QueryParameter('expires', required: true)]
    public function confirmExpiring(string $token): JsonResponse
    {
        return response()->json(['confirmed' => true]);
    }

    /**
     * Confirm a subscription, without documenting the link's expiry.
     */
    #[Group('Links')]
    #[IgnoreParam('expires', in: 'query')]
    public function confirmWithoutExpiry(string $token): JsonResponse
    {
        return response()->json(['confirmed' => true]);
    }
}
