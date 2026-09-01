<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Modular\Modules\Alpha\Api\Versions;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;

/** The second module the one glob entry has to reach, and a change that is not part of the chain. */
#[ApiVersionChange(since: '2026-10-01', description: 'Forms no longer publish `subtotal`.')]
#[RemovedResponseField(schema: FormData::class, field: 'subtotal', type: 'integer')]
final class MmFormLostSubtotal {}
