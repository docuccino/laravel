<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedRefComposed;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AuthorData;

/**
 * The later of the pair: the older versions published a `reviewer`, and it held whatever an author
 * looked like THEN — which is a different shape below the change beside this one.
 */
#[ApiVersionChange(since: '2026-12-01', description: 'Articles no longer publish `reviewer`.')]
#[RemovedResponseField(schema: ArticleData::class, field: 'reviewer', type: AuthorData::class)]
final class AaArticleLostItsReviewer {}
