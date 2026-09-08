<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization\Policies;

use Docuccino\Laravel\Tests\Fixtures\Authorization\Illuminated;
use Illuminate\Foundation\Auth\User;

/** Registered against {@see Illuminated}, with the body the check reports: nothing here can deny. */
final class IlluminatedPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }
}
