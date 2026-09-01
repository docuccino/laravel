<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedUntyped;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;

/** A removal that states no type: the field is published with no constraints, on purpose and silently. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms no longer publish `annotations`.')]
#[RemovedResponseField(schema: FormData::class, field: 'annotations')]
final class FormLostAnUnknownShape {}
