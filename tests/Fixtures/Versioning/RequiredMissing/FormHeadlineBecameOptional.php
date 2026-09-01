<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RequiredMissing;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeResponseFieldOptional;
use Workbench\App\Data\FormData;

/** The declaration rotted: the code has no `headline` to have made optional. */
#[ApiVersionChange(since: '2026-09-01', description: 'A form omits `headline` where it has none.')]
#[MadeResponseFieldOptional(schema: FormData::class, field: 'headline')]
final class FormHeadlineBecameOptional {}
