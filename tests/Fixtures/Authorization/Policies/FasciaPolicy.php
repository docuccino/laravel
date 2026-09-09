<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization\Policies;

use Docuccino\Laravel\Tests\Fixtures\Authorization\Fascia;
use Illuminate\Foundation\Auth\User;

/**
 * {@see Fascia}'s policy under the conventional name — the answer the resolution would give if the
 * model's attribute could be read.
 */
final class FasciaPolicy
{
    /** Cannot deny: the whole body is a literal `return true`. */
    public function viewAny(?User $user): bool
    {
        return true;
    }
}
