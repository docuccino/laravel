<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedScopedRef;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;
use Workbench\App\Data\FormTreeData;

/**
 * A scoped removal whose re-added field points at a component that leads nowhere near the schema being
 * copied. The fork can be written, and the pointer is the honest thing to leave in it.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'The tree list no longer publishes `form`.')]
#[AppliesTo('GET /api/versioned-trees')]
#[RemovedResponseField(schema: FormTreeData::class, field: 'form', type: FormData::class)]
final class OnlyTheTreeListLostItsForm {}
