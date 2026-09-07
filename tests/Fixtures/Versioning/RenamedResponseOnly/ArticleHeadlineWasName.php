<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedResponseOnly;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;
use Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedRequest\ArticleHeadingWasName;

/**
 * The mirror of {@see ArticleHeadingWasName}
 * on the other half of the wire: `headline` is what the RESPONSE publishes, and the request body of the
 * same class calls it `heading`.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'An article publishes `headline` where it published `name`.')]
#[RenamedResponseField(schema: ArticleData::class, from: 'name', to: 'headline')]
final class ArticleHeadlineWasName {}
