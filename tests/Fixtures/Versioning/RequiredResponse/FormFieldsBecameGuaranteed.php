<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RequiredResponse;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeResponseFieldRequired;
use Workbench\App\Data\FormData;

/**
 * Both of the schema's required fields, so the older document is left with an empty `required` — which
 * is the case that has to come out as the keyword being ABSENT rather than as an empty array.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'A form always carries `id` and `title`; before this either could be absent.')]
#[MadeResponseFieldRequired(schema: FormData::class, field: 'id')]
#[MadeResponseFieldRequired(schema: FormData::class, field: 'title')]
final class FormFieldsBecameGuaranteed {}
