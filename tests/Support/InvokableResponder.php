<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A `respond()` callback registered as an object — `$exceptions->respond(new InvokableResponder)` — or as
 * `[$object, 'reshape']`. Unlike a render callback, `respond()` stores the callable as given, so the
 * reflector converts it itself and must still land on the method.
 */
final class InvokableResponder
{
    public function __invoke(Response $rendered, Throwable $thrown, Request $incoming): Response
    {
        return $rendered;
    }

    public function reshape(Response $rendered): Response
    {
        return $rendered;
    }
}
