<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\ScopedNowhereTwice;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/**
 * Two renames over ONE schema behind one selector that names nothing. The scope is walked per rename, so
 * the report about the SCOPE — which says nothing about either field — comes out twice, byte for byte.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'A form publishes `title` and `publishedAt` where it published `name` and `issued`.')]
#[AppliesTo('GET /api/forms-as-they-were-called-then')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]
#[RenamedResponseField(schema: FormData::class, from: 'issued', to: 'publishedAt')]
final class TwoRenamesOnARouteThatMoved {}
