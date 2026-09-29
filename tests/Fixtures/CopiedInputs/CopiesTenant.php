<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Docuccino\Attributes\Description;

/** A trait method a request takes as its hook under an alias, with an attribute on the line above. */
trait CopiesTenant
{
    #[Description('Copies the tenant into the input.')]
    protected function copyTenant(): void
    {
        $this->merge(['tenant' => $this->header('X-Tenant')]);
    }
}
