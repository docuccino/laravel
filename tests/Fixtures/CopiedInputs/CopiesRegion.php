<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

/** A trait hook with an attribute on the line above. */
trait CopiesRegion
{
    #[\Override]
    protected function prepareForValidation(): void
    {
        $this->merge(['region' => $this->header('X-Region')]);
    }
}
