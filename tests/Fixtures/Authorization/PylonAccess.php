<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Foundation\Auth\User;

/**
 * The policy {@see Pylon}'s `#[UsePolicy]` attribute names, placed where no naming convention would
 * look for it: reaching this at all proves the attribute step was consulted.
 */
final class PylonAccess
{
    /** Cannot deny: the whole body is a literal `return true`. */
    public function viewAny(?User $user): bool
    {
        return true;
    }
}
