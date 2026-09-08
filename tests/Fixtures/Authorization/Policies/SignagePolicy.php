<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization\Policies;

use Docuccino\Laravel\Tests\Fixtures\Authorization\Signage;

/**
 * The policy Laravel's convention resolves {@see Signage} to. It declares nothing: a reader sent here to
 * tighten `viewAny()` would find no such method.
 */
final class SignagePolicy extends BaseSignagePolicy {}
