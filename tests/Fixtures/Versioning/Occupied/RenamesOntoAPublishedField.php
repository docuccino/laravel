<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Occupied;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/** Renaming `title` back to `id` would collapse it onto a field the schema already publishes. */
#[ApiVersionChange(since: '2026-09-01', description: 'Renames a form field.')]
#[RenamedResponseField(schema: FormData::class, from: 'id', to: 'title')]
final class RenamesOntoAPublishedField {}
