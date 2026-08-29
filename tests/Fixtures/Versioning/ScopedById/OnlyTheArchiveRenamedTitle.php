<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\ScopedById;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/** Scoped by the operationId a consumer quotes back, rather than by the signature. */
#[ApiVersionChange(since: '2026-09-01', description: 'The archived forms publish `title` where they published `name`.')]
#[AppliesTo('listArchivedForms')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]
final class OnlyTheArchiveRenamedTitle {}
