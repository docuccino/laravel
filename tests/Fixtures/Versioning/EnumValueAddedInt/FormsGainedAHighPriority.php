<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EnumValueAddedInt;

use Docuccino\Attributes\Versioning\AddedEnumValue;
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Workbench\App\Enums\WidgetPriority;

/** The int-backed half: the value is a number on the wire and the declaration says so. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms can be prioritised above normal.')]
#[AddedEnumValue(enum: WidgetPriority::class, value: 10)]
final class FormsGainedAHighPriority {}
