<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Mixed;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/** A date where the document's other versions are semver: nothing can order the two. */
#[ApiVersionChange(since: '2026-09-01', description: 'A form publishes `title` where it published `name`.')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]
final class DatedAmongSemver {}
