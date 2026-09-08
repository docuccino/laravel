<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization\Policies;

use Illuminate\Foundation\Auth\User;

/** Where {@see SignagePolicy}'s abilities are really written. Only ever reflected and parsed. */
abstract class BaseSignagePolicy
{
    /** Cannot deny, and the file this is in is not the file the gate names. */
    public function viewAny(?User $user): bool
    {
        return true;
    }
}
