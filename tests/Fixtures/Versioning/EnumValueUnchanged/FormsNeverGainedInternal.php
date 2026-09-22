<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EnumValueUnchanged;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedEnumValue;
use Workbench\App\Enums\FormVisibility;

/** A removal of a value the set still publishes — the declaration read the other way round. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms stopped being internal.')]
#[RemovedEnumValue(enum: FormVisibility::class, value: 'internal')]
final class FormsNeverGainedInternal {}
