<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Invalid;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/** A rename with no old name to publish, and one that renames a field to itself. */
#[ApiVersionChange(since: '2026-09-01', description: 'Renames a form field.')]
#[RenamedResponseField(schema: FormData::class, from: '', to: 'title')]
#[RenamedResponseField(schema: FormData::class, from: 'title', to: 'title')]
final class EmptyRename {}
