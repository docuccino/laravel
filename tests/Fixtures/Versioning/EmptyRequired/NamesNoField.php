<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EmptyRequired;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeResponseFieldOptional;
use Workbench\App\Data\FormData;

/** A verb with nothing to name is not a narrower verb, it is an unreadable one. */
#[ApiVersionChange(since: '2026-09-01', description: 'Names no field.')]
#[MadeResponseFieldOptional(schema: FormData::class, field: '  ')]
final class NamesNoField {}
