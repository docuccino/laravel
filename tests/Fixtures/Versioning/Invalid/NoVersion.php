<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Invalid;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/** A change with no version at all: nothing can tell which documents it applies to. */
#[ApiVersionChange(since: '  ', description: 'Renames a form field.')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]
final class NoVersion {}
