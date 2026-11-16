<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions\Rfc9457;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use Workbench\App\Exceptions\OrderConflictException;

/**
 * How the API answers every error: one RFC 9457 problem per kind of exception, chosen by a `match` and
 * built by the {@see Problem} it hands back, which the framework sends through its `toResponse()`.
 */
final class Problems
{
    /** The problem `$e` is answered with. */
    public static function for(Throwable $e): Problem
    {
        return match (true) {
            $e instanceof ProblemException => $e->toProblem(),
            $e instanceof ValidationException => self::invalid($e),
            $e instanceof AuthenticationException => self::of(401, 'Unauthenticated', 'Sign in to continue.'),
            $e instanceof OrderConflictException => new Problem(409, 'Conflict', $e->getMessage(), 'https://errors.test/problems/conflict'),
            $e instanceof HttpExceptionInterface => self::of($e->getStatusCode(), 'Request refused', $e->getMessage()),
            default => self::of(500, 'Server Error', 'Something went wrong on our side.'),
        };
    }

    /** The same problem, sent here rather than by the framework: null for a request outside the API. */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return self::for($e)->toResponse($request);
    }

    private static function invalid(ValidationException $e): Problem
    {
        return new Problem($e->status, 'Unprocessable Content', $e->getMessage(), 'https://errors.test/problems/validation', [
            'errors' => $e->errors(),
        ]);
    }

    private static function of(int $status, string $title, string $detail): Problem
    {
        return new Problem($status, $title, $detail);
    }
}
