<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Scoped;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/** Scoped to ONE of the two operations that publish `FormData`, so that version's document forks. */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms list publishes `title` where it published `name`.')]
#[AppliesTo('GET /api/versioned-forms')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]
final class OnlyTheListRenamedTitle {}
