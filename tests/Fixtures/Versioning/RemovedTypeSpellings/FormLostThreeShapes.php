<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedTypeSpellings;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;

/** The `[]` and `?` suffixes, through a real build rather than through the table on its own. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms no longer publish `tags`, `retiredAt` or `scores`.')]
#[RemovedResponseField(schema: FormData::class, field: 'tags', type: 'string[]')]
#[RemovedResponseField(schema: FormData::class, field: 'retiredAt', type: 'string?')]
#[RemovedResponseField(schema: FormData::class, field: 'scores', type: 'number[]?')]
final class FormLostThreeShapes {}
