<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EmptyRenamedRequest;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedRequestField;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;

/** A request rename with an empty end, which names nothing to move. */
#[ApiVersionChange(since: '2026-09-01', description: 'An article is submitted with something.')]
#[RenamedRequestField(schema: ArticleData::class, from: '', to: 'heading')]
final class AcceptedNothingUnderNoName {}
