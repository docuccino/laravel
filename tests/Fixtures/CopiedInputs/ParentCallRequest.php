<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

/** Runs its base's hook, whose writes this body does not show, before its own copy. */
final class ParentCallRequest extends BaseCopiesRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge(['trace' => $this->header('X-Trace-Id')]);
    }
}
