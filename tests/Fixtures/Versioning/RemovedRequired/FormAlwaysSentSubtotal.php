<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedRequired;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;

/**
 * The same removal, declared as one the older versions ALWAYS sent. That makes their document stricter
 * than today's — which is the half a per-version contract test can refuse — and it makes every example
 * standing where the schema governs unpublishable, because none of them carries a field the code does
 * not have.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms no longer publish `subtotal`.')]
#[RemovedResponseField(schema: FormData::class, field: 'subtotal', type: 'integer', required: true)]
final class FormAlwaysSentSubtotal {}
