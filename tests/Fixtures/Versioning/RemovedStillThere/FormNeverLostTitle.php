<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedStillThere;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;

/** A removal of a field the code still publishes, which is a declaration describing a change nobody made. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms no longer publish `title`.')]
#[RemovedResponseField(schema: FormData::class, field: 'title', type: 'string')]
final class FormNeverLostTitle {}
