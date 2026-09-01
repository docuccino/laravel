<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RequiredUnchanged;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeResponseFieldRequired;
use Workbench\App\Data\FormData;

/**
 * The direction read backwards: `publishedAt` is OPTIONAL in the code today, so no version can have
 * made it required and there is nothing to undo.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'A form always carries `publishedAt`.')]
#[MadeResponseFieldRequired(schema: FormData::class, field: 'publishedAt')]
final class FormPublishedAtBecameGuaranteed {}
