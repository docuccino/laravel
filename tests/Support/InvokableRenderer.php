<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * An idiomatic invokable exception renderer — the shape an app registers with
 * `$exceptions->render(new InvokableRenderer)`. Laravel wraps it via `Closure::fromCallable()`, so the
 * reflector must recognise the wrapped closure as the `__invoke` method it really is.
 */
final class InvokableRenderer
{
    public function __invoke(ModelNotFoundException $e): JsonResponse
    {
        return response()->json(['error' => 'gone'], 410);
    }
}
