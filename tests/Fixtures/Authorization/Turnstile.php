<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Illuminate\Database\Eloquent\Model;

/**
 * Authorized by a conventional policy that has CONSTRUCTOR DEPENDENCIES, which is what makes building
 * one at documentation-build time a side effect rather than a lookup: the container resolves whatever
 * the policy injects and fires every `resolving` hook the application registered.
 *
 * @property int $id
 */
final class Turnstile extends Model {}
