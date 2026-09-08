<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization\Policies;

use Docuccino\Laravel\Tests\Fixtures\Authorization\Kiosk;
use Docuccino\Laravel\Tests\Fixtures\Authorization\KioskScope;
use Illuminate\Foundation\Auth\User;

/**
 * An ordinary policy, found by convention, holding one method of each shape the reachability check has
 * to tell apart. Only ever reflected and parsed — nothing here is called.
 */
final class KioskPolicy
{
    /** Cannot deny: the whole body is a literal `return true`, and a guest is admitted too. */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /** Cannot deny either, but only where the route is behind auth — this refuses a guest outright. */
    public function view(User $user, Kiosk $kiosk): bool
    {
        return true;
    }

    /** Denies, plainly. */
    public function update(User $user, Kiosk $kiosk): bool
    {
        return $user->getAuthIdentifier() === $kiosk->id;
    }

    /**
     * Denies, and mentions neither the user nor a permission while doing so — the shape that makes
     * "ignores $user" and "names no permission" both useless as tests.
     */
    public function inspect(User $user, Kiosk $kiosk): bool
    {
        return KioskScope::current() instanceof KioskScope;
    }

    /** Denies: `true` is in there, and it is reached conditionally. */
    public function audit(User $user, Kiosk $kiosk): bool
    {
        if ($kiosk->id > 0) {
            return true;
        }

        return false;
    }
}
