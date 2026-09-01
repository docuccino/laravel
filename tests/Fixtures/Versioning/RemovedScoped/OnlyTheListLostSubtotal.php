<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedScoped;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;

/** Scoped to ONE of the two operations publishing `FormData`, so that version's document forks. */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms list no longer publishes `subtotal`.')]
#[AppliesTo('GET /api/versioned-forms')]
#[RemovedResponseField(schema: FormData::class, field: 'subtotal', type: 'integer')]
final class OnlyTheListLostSubtotal {}
