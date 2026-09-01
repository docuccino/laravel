<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedScopedSelfReferential;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;

/**
 * A scoped removal that puts back a field typed as the very schema it is editing. The private copy the
 * fork would write points at the shared component, so the operation would publish the older shape at
 * the top and today's one level down — the self-reference limit, reached through a verb rather than
 * through the way the class was written.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms list no longer publishes `parent`.')]
#[AppliesTo('GET /api/versioned-forms')]
#[RemovedResponseField(schema: FormData::class, field: 'parent', type: FormData::class)]
final class OnlyTheListLostAChildForm {}
