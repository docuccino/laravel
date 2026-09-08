<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Foundation\Auth\User;

/**
 * The policy for {@see Marquee}, deliberately named and placed where no convention would look for it:
 * only an explicit `Gate::policy()` registration reaches it.
 */
final class MarqueeAccess
{
    public function view(User $user, Marquee $marquee): bool
    {
        return true;
    }
}
