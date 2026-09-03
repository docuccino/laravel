<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The everyday `$request->merge()` idiom, as a real middleware in a real stack. */
final class MergesATenant
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $request->merge(['tenant' => 'acme']);

        return $next($request);
    }
}
