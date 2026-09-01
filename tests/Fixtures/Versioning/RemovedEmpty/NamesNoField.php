<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedEmpty;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;

/** A removal that names no field, which is nothing this can be applied to. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms lost something.')]
#[RemovedResponseField(schema: FormData::class, field: '  ', type: 'integer')]
final class NamesNoField {}
