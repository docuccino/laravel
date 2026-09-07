<?php

declare(strict_types=1);

namespace Workbench\App\Api\RequestVersions;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedRequestField;
use Workbench\App\Http\Middleware\UpgradeFromPinnedApiVersion;
use Workbench\App\Http\Requests\StoreVersionedFormRequest;

/**
 * A form was created with `name` before 2026-09-01, and is created with `title` today.
 *
 * The body is empty on purpose. The imperative half — the code that walks an incoming body forward to
 * the shape today's validation demands — belongs to the application's own versioning runtime, and
 * Docuccino never reads or runs it. This workbench keeps it in {@see UpgradeFromPinnedApiVersion}.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'A form is created with `title` where it was created with `name`.')]
#[RenamedRequestField(schema: StoreVersionedFormRequest::class, from: 'name', to: 'title')]
final class FormsWereCreatedWithAName {}
