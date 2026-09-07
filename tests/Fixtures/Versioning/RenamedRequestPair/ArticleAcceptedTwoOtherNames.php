<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedRequestPair;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedRequestField;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;

/** Two request renames on one change, which is where the required list has to follow both. */
#[ApiVersionChange(since: '2026-09-01', description: 'An article is submitted with `heading` and `body` where it was submitted with `name` and `content`.')]
#[RenamedRequestField(schema: ArticleData::class, from: 'name', to: 'heading')]
#[RenamedRequestField(schema: ArticleData::class, from: 'content', to: 'body')]
final class ArticleAcceptedTwoOtherNames {}
