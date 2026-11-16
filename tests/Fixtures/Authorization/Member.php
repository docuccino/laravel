<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Foundation\Auth\User;
use Spatie\Permission\Traits\HasRoles;

/**
 * A signed-in user spatie/laravel-permission checks, shaped as the package's installation guide shapes an
 * application's `User` model: the framework's authenticatable base and the `HasRoles` trait.
 *
 * @property int $id
 */
final class Member extends User
{
    use HasRoles;

    protected $guarded = [];
}
