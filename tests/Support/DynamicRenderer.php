<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Response;

/**
 * An invokable exception renderer whose body genuinely folds to nothing: a plain (non-JSON) response
 * whose status comes from the exception at run time. The inferred-handler tier has no body and no status
 * to publish for it, so it defers to the next tier and notes the deferral — which is the shape the
 * warm/cold note-replay tests need to exist for real rather than by scripting alone.
 */
final class DynamicRenderer
{
    public function __invoke(ModelNotFoundException $e): Response
    {
        return response($e->getMessage(), $e->getCode() === 0 ? 404 : (int) $e->getCode());
    }
}
