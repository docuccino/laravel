<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\InferredHandler;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A missing-model exception that renders itself only sometimes: where its `render()` answers, the handler
 * never prepares it, and where it hands back null, the callbacks after it see the prepared exception.
 */
final class SelfRenderingMissingModel extends ModelNotFoundException
{
    public bool $renders = false;

    public function render(): ?Response
    {
        return $this->renders ? response()->json(['gone' => true], 410) : null;
    }
}
