<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EnumValueRemovedColliding;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedEnumValue;
use Workbench\App\Enums\FormVisibility;

/**
 * A value put back under a name the set already publishes. Two members sharing an identifier is what a
 * generated client cannot have, so the set publishes no names at all rather than a colliding pair.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms had a second internal visibility.')]
#[RemovedEnumValue(enum: FormVisibility::class, value: 'internal-only', name: 'Internal')]
final class FormsUsedToBeInternalToo {}
