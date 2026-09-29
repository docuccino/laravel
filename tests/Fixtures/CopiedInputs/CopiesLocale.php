<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

/** A hook that competes with another trait's for the same name. */
trait CopiesLocale
{
    protected function prepareForValidation(): void
    {
        $this->merge(['locale' => $this->header('X-Locale')]);
    }
}
