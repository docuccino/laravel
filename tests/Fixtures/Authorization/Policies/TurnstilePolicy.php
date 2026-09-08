<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization\Policies;

use Docuccino\Laravel\Tests\Fixtures\Authorization\Turnstile;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Auth\User;

/**
 * The conventional policy for {@see Turnstile}, written the way an application writes one that needs a
 * collaborator — so it can only be built through the container, and building it runs code.
 *
 * The counter is the whole point: the reachability check answers with a class NAME and a method's
 * source, and a check that reached for `Gate::getPolicyFor()` instead would build this and move it. It
 * is the one observable a test has, because every other policy in this suite has a trivial constructor
 * and would let that regression through in silence.
 */
final class TurnstilePolicy
{
    /** Bumped by every construction, and by nothing else. */
    public static int $constructed = 0;

    public function __construct(public readonly Repository $config)
    {
        self::$constructed++;
    }

    /** Cannot deny — so the check has to resolve this policy, by name, to say so. */
    public function viewAny(?User $user): bool
    {
        return true;
    }
}
