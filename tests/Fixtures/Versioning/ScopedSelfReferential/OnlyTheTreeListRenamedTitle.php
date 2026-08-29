<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\ScopedSelfReferential;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormTreeData;

/** A scoped rename of a shape that contains itself, which cannot be forked. */
#[ApiVersionChange(since: '2026-09-01', description: 'A form tree publishes `title` where it published `name`.')]
#[AppliesTo('GET /api/versioned-trees')]
#[RenamedResponseField(schema: FormTreeData::class, from: 'name', to: 'title')]
final class OnlyTheTreeListRenamedTitle {}
