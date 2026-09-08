<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization\Policies;

use Illuminate\Foundation\Auth\User;

/**
 * A conventional policy whose `before()` runs ahead of every ability it covers, so `view()` returning
 * `true` says nothing about whether the gate can deny.
 */
final class PlacardPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->getAuthIdentifier() === 1 ? true : null;
    }

    public function view(User $user): bool
    {
        return true;
    }
}
