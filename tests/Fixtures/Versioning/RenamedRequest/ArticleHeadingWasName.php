<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedRequest;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedRequestField;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;

/**
 * A rename on the REQUEST half of a class the document publishes on both. `heading` is the name the
 * request body accepts today; the response half of the same class calls the same field `headline`, so
 * a verb that resolved the response node would find nothing called `heading` there and say so.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'An article is submitted with `heading` where it was submitted with `name`.')]
#[RenamedRequestField(schema: ArticleData::class, from: 'name', to: 'heading')]
final class ArticleHeadingWasName {}
