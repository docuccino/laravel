<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\IgnoreResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use RuntimeException;
use Workbench\App\Exceptions\UnsupportedRadiusException;

/**
 * Throws with no status that assert what the application already guarantees — the user its middleware put
 * there, the class a subclass must name — beside the same throws behind a test of a value, which a request
 * can fail, and exceptions behind a type test that the framework or the application renders at a status.
 * Each action otherwise answers 200. Inert — never dispatched.
 */
final class AssertionGuardController
{
    public function listWithNeverHelper(): JsonResponse
    {
        $rowClass = $this->dataClass() ?? $this->missingDataClass();

        return response()->json(['ok' => true, 'class' => $rowClass]);
    }

    public function unlessUser(Request $request): JsonResponse
    {
        throw_unless($request->user() instanceof User, RuntimeException::class, 'The route runs behind auth.');

        return response()->json(['ok' => true]);
    }

    public function unlessUserAuthenticated(Request $request): JsonResponse
    {
        throw_unless($request->user() instanceof User, AuthenticationException::class, 'Sign in first.');

        return response()->json(['ok' => true]);
    }

    public function ifSystemAuthenticated(Request $request): JsonResponse
    {
        throw_if($request->boolean('system'), AuthenticationException::class, 'A system user cannot sign in.');

        return response()->json(['ok' => true]);
    }

    public function radiusOutOfRange(Request $request): JsonResponse
    {
        throw_if($request->float('radius') <= 0.0, UnsupportedRadiusException::class, 'The radius must be above zero.');

        return response()->json(['ok' => true]);
    }

    public function valueGuard(Request $request): JsonResponse
    {
        if ($request->integer('count') > 100) {
            throw new RuntimeException('Too many.');
        }

        return response()->json(['ok' => true]);
    }

    public function sameClassTwoSites(Request $request): JsonResponse
    {
        if (! $request->user() instanceof User) {
            throw new RuntimeException('The route runs behind auth.');
        }

        if ($request->integer('count') > 100) {
            throw new RuntimeException('Too many.');
        }

        return response()->json(['ok' => true]);
    }

    /** An ignore written for the 500 an assertion used to publish. */
    #[IgnoreResponse(status: 500)]
    public function ignoredAssertion(): JsonResponse
    {
        $rowClass = $this->dataClass() ?? $this->missingDataClass();

        return response()->json(['ok' => true, 'class' => $rowClass]);
    }

    /** @return class-string|null */
    private function dataClass(): ?string
    {
        return null;
    }

    private function missingDataClass(): never
    {
        throw new LogicException(self::class.' must declare dataClass().');
    }
}
