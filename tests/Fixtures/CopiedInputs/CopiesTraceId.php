<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

/** A copy a request takes as its hook under a name of its own choosing. */
trait CopiesTraceId
{
    protected function copyTrace(): void
    {
        $this->merge(['trace' => $this->header('X-Trace-Id')]);
    }
}
