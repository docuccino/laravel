<?php

declare(strict_types=1);

namespace Workbench\App\Api\Versions;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;
use Workbench\App\Http\Middleware\TitleReplacesName;

/**
 * A form's `title` was published as `name` before 2026-09-01.
 *
 * The body is empty on purpose. The imperative half — the code that walks a response back to the older
 * shape — belongs to the application's own versioning runtime, and Docuccino never reads or runs it.
 * This workbench keeps it in {@see TitleReplacesName}.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'A form publishes `title` where it published `name`.')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]
final class FormTitleReplacesName {}
