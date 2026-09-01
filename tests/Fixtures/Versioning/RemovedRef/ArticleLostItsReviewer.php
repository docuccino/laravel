<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedRef;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;

/**
 * The reading that needs no type grammar at all: the removed field held a class the document already
 * publishes, so the older version's document points at that component.
 *
 * `ArticleData` pins its own diff identity with `#[SchemaId]`, so this is also the guard that a removal
 * resolves the schema it names through the one mint rather than through the class name.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Articles no longer publish `reviewer`.')]
#[RemovedResponseField(
    schema: ArticleData::class,
    field: 'reviewer',
    type: AuthorData::class,
    description: 'Who signed the article off.',
)]
final class ArticleLostItsReviewer {}
