<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\SelfReferentialEverywhere;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormTreeData;

/** The same self-referential shape, scoped to every operation that publishes it — so nothing forks. */
#[ApiVersionChange(since: '2026-09-01', description: 'A form tree publishes `title` where it published `name`.')]
#[AppliesTo('GET /api/versioned-trees*')]
#[RenamedResponseField(schema: FormTreeData::class, from: 'name', to: 'title')]
final class EveryTreeOperationRenamedTitle {}
