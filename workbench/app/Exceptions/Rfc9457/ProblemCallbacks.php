<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions\Rfc9457;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The render callbacks an application registers in `bootstrap/app.php` to answer every error with an RFC
 * 9457 problem, through the static helper that picks one.
 */
final class ProblemCallbacks
{
    /** Hands the framework the {@see Problem}, which it sends through `toResponse()`. */
    public function problem(): callable
    {
        return static fn (Throwable $e): Responsable => Problems::for($e);
    }

    /** Sends the problem itself, and leaves a request outside the API to the framework. */
    public function rendered(): callable
    {
        return static fn (Throwable $e, Request $request): ?JsonResponse => Problems::render($e, $request);
    }
}
