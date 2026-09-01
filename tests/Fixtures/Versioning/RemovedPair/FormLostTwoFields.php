<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedPair;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;

/** Two removals on one schema, written one way round. Its mirror is `RemovedPairReversed`. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms no longer publish `subtotal` or `archivedAt`.')]
#[RemovedResponseField(schema: FormData::class, field: 'subtotal', type: 'integer')]
#[RemovedResponseField(schema: FormData::class, field: 'archivedAt', type: 'string')]
final class FormLostTwoFields {}
