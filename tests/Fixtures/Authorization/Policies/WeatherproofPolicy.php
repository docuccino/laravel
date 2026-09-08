<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization\Policies;

use Docuccino\Laravel\Tests\Fixtures\Authorization\Weatherproof;
use Illuminate\Foundation\Auth\User;

/**
 * Registered against {@see Weatherproof}, and the opposite verdict to {@see IlluminatedPolicy}: this one
 * CAN deny, so which of the two a gate resolved to is visible in whether the check reports at all.
 */
final class WeatherproofPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }
}
