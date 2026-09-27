<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A renderer registered as an `[$object, 'method']` pair or a first-class callable
 * (`(new PairRenderer)->handle(...)`). Both forms reach `Handler::renderable()` as a
 * `Closure::fromCallable()` naming the `handle` method — the reflector must analyse that method.
 */
final class PairRenderer
{
    public function handle(NotFoundHttpException $e): JsonResponse
    {
        return response()->json(['error' => 'gone'], 410);
    }
}
