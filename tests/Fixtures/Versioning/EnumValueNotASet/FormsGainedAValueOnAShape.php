<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EnumValueNotASet;

use Docuccino\Attributes\Versioning\AddedEnumValue;
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Workbench\App\Data\VisibleFormData;

/** A value set named on a class the document publishes as an object, which holds no values to move. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms gained a value.')]
#[AddedEnumValue(enum: VisibleFormData::class, value: 'draft')]
final class FormsGainedAValueOnAShape {}
