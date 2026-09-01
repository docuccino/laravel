<?php

declare(strict_types=1);

namespace Workbench\App\Api\EntryVersions;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeResponseFieldOptional;
use Workbench\App\Api\Versions\FormTitleReplacesName;
use Workbench\App\Data\FormEntryData;
use Workbench\App\Http\Middleware\SubmittedAtAlwaysSent;

/**
 * A form entry omits `submittedAt` until it has one; before 2026-09-01 the key was always there.
 *
 * A second changes directory beside {@see FormTitleReplacesName} rather than a second change in it:
 * this history is over its own route and its own shape, so the rename suite goes on asserting about
 * the rename and nothing else.
 *
 * The body is empty on purpose. The imperative half is {@see SubmittedAtAlwaysSent}, and Docuccino
 * never reads or runs it.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'A form entry omits `submittedAt` until it is submitted; before this the key was always sent.')]
#[MadeResponseFieldOptional(schema: FormEntryData::class, field: 'submittedAt')]
final class EntrySubmittedAtBecameOptional {}
