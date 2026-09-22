<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EnumValueUnresolved;

use Docuccino\Attributes\Versioning\AddedEnumValue;
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Workbench\App\Enums\Season;

/** An enum this document publishes nowhere, so there is no set to move a value in or out of. */
#[ApiVersionChange(since: '2026-09-01', description: 'Something gained a season.')]
#[AddedEnumValue(enum: Season::class, value: 'spring')]
final class ASetNobodyPublishes {}
