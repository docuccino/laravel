<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * A controller that puts its own authenticator on every action, the way Laravel 11+ declares controller
 * middleware: the route itself names none.
 */
final class SignedInController implements HasMiddleware
{
    /** @return list<string> */
    public static function middleware(): array
    {
        return ['auth'];
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
