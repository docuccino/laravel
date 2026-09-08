<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization\Policies;

use Illuminate\Foundation\Auth\User;

/** An ability shared by trait, so the body lives in neither the policy's file nor a parent's. */
trait AdmitsEveryone
{
    public function viewAny(?User $user): bool
    {
        return true;
    }
}
