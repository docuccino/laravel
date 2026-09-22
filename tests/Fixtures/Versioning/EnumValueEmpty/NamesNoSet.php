<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EnumValueEmpty;

use Docuccino\Attributes\Versioning\AddedEnumValue;
use Docuccino\Attributes\Versioning\ApiVersionChange;

/** A value belonging to no set, which is nothing this can be applied to. */
#[ApiVersionChange(since: '2026-09-01', description: 'Something gained a value.')]
#[AddedEnumValue(enum: '  ', value: 'draft')]
final class NamesNoSet {}
