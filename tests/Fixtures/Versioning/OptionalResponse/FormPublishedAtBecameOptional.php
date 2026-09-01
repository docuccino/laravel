<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OptionalResponse;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeResponseFieldOptional;
use Workbench\App\Data\FormData;

/** `publishedAt` is optional today and was always sent before, so the older document requires it. */
#[ApiVersionChange(since: '2026-09-01', description: 'A form omits `publishedAt` until it is published; before this it was always sent.')]
#[MadeResponseFieldOptional(schema: FormData::class, field: 'publishedAt')]
final class FormPublishedAtBecameOptional {}
