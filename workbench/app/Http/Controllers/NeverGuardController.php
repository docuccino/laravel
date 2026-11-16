<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workbench\App\Http\Controllers\Concerns\RefusesRequests;
use Workbench\App\Services\RequestGuard;

/**
 * Guard helpers written outside the controller, refusing a request the action otherwise answers with a 200:
 * a trait method and a method on an injected service, returning `never`, and two returning `void` beside
 * them; and a helper throwing a response for the framework to send, written as a private and a static
 * method. The framework answers the same whichever the helper returns.
 */
final class NeverGuardController
{
    use RefusesRequests;

    public function traitNeverHttp(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedNeverHttp();
        }

        return response()->json(['ok' => true]);
    }

    public function traitNeverAbort(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedNeverAbort();
        }

        return response()->json(['ok' => true]);
    }

    public function traitNeverCustom(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedNeverCustom();
        }

        return response()->json(['ok' => true]);
    }

    public function traitNeverValidation(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedNeverValidation();
        }

        return response()->json(['ok' => true]);
    }

    public function traitNeverCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedNeverCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function traitVoidCustom(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedVoidCustom();
        }

        return response()->json(['ok' => true]);
    }

    public function traitVoidCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->sharedVoidCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function serviceNeverCustom(Request $request, RequestGuard $guard): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $guard->neverCustom();
        }

        return response()->json(['ok' => true]);
    }

    public function privateNeverCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            $this->neverCarried();
        }

        return response()->json(['ok' => true]);
    }

    public function staticNeverCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            self::staticallyNeverCarried();
        }

        return response()->json(['ok' => true]);
    }

    private function neverCarried(): never
    {
        throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
    }

    private static function staticallyNeverCarried(): never
    {
        throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
    }
}
