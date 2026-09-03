<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WeakMap;

/**
 * Keeps what a request body was, read at the FRONT of the middleware stack.
 *
 * By the time an assertion asks, the parameter bag is no longer the message: `TrimStrings` and
 * `ConvertEmptyStringsToNull` ship in Laravel's default global stack and rewrite it in place, and
 * `$request->merge()` adds fields no client sent. {@see ApiContract::captureRequestBodies()} prepends
 * this so the record is taken first.
 */
final class CaptureRequestBody
{
    /**
     * A `WeakMap` because a record's life is its request's: nothing has to remember to clear it between
     * tests, and it is invisible to the application, which a bag or an attribute would not be.
     *
     * @var WeakMap<Request, array{fields: array<array-key, mixed>, files: array<array-key, mixed>, type: string|null, body: string}>|null
     */
    private static ?WeakMap $records = null;

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        self::$records ??= new WeakMap;

        self::$records[$request] = [
            'fields' => $request->request->all(),
            'files' => $request->files->all(),
            'type' => $request->headers->get('Content-Type'),
            'body' => $request->getContent(),
        ];

        return $next($request);
    }

    /**
     * What this request arrived carrying, or null where nothing recorded it.
     *
     * @return array{fields: array<array-key, mixed>, files: array<array-key, mixed>, type: string|null, body: string}|null
     */
    public static function of(Request $request): ?array
    {
        return self::$records[$request] ?? null;
    }
}
