<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\Abilities;
use Docuccino\Attributes\OptionallyAuthenticated;
use Docuccino\Attributes\Security;
use Illuminate\Http\JsonResponse;

/**
 * Idiomatic usage of the attribute security family: an OR-list of explicit requirements, an
 * optionally-authenticated endpoint, and a declared Sanctum token ability — the cases where the
 * check lives outside route middleware (Gates, policies, `tokenCan()` in the body).
 */
final class AuthAttributesController
{
    /**
     * Reports: readable with an OAuth2 token carrying `reports.read`, or with an API key.
     */
    #[Security('oauth2', ['reports.read'])]
    #[Security('apiKey')]
    public function reports(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    /**
     * Public feed: works signed-out, richer when a bearer token is present.
     */
    #[OptionallyAuthenticated]
    public function feed(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    /**
     * Publish: the ability is checked in the body via `$request->user()->tokenCan('posts:publish')`.
     */
    #[Abilities('posts:publish')]
    public function publish(): JsonResponse
    {
        return response()->json(['published' => true]);
    }
}
