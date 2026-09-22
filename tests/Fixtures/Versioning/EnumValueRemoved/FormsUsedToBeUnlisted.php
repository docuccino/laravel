<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EnumValueRemoved;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedEnumValue;
use Workbench\App\Enums\FormVisibility;

/**
 * A value this version took away. The code has no case left to read it off, so the declaration carries
 * what older clients called it and what it meant.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms are no longer unlisted; use `invited`.')]
#[RemovedEnumValue(
    enum: FormVisibility::class,
    value: 'unlisted',
    name: 'Unlisted',
    description: 'Reachable by link, and left out of every index.',
)]
final class FormsUsedToBeUnlisted {}
