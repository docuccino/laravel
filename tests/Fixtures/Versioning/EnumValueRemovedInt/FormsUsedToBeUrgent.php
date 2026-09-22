<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EnumValueRemovedInt;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedEnumValue;
use Workbench\App\Enums\WidgetPriority;

/**
 * A value put back into an int-backed set that publishes only the positional prose array, so the
 * completeness map is not in play and the positional array grows with an empty-string gap.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms could be urgent.')]
#[RemovedEnumValue(enum: WidgetPriority::class, value: 20, name: 'Urgent')]
final class FormsUsedToBeUrgent {}
