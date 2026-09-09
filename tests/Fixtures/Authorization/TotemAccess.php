<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Docuccino\Laravel\Tests\Fixtures\Authorization\Policies\TurnstilePolicy;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Auth\User;

/**
 * The policy {@see Totem}'s `#[UsePolicy]` attribute names, placed where no naming convention would
 * look for it: reaching this at all proves an attribute branch was consulted.
 *
 * Counts its own constructions, and has a collaborator so it can only be built through the container,
 * for the reason {@see TurnstilePolicy} states — the parent-attribute branch is one more resolution
 * step a mirror could be tempted to answer with `Gate::getPolicyFor()`.
 */
final class TotemAccess
{
    /** Bumped by every construction, and by nothing else. */
    public static int $constructed = 0;

    public function __construct(public readonly Repository $config)
    {
        self::$constructed++;
    }

    /** Cannot deny, so a resolution that reached it can be observed in what the check reports. */
    public function viewAny(?User $user): bool
    {
        return true;
    }
}
