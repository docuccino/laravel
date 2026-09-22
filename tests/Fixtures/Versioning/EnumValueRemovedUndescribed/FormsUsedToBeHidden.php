<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EnumValueRemovedUndescribed;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedEnumValue;
use Workbench\App\Enums\FormVisibility;

/** The same, with no sentence for the value it puts back into a set that describes every other one. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms are no longer hidden.')]
#[RemovedEnumValue(enum: FormVisibility::class, value: 'hidden')]
final class FormsUsedToBeHidden {}
