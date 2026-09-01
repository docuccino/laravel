<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OptionalRequestBody;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeRequestFieldOptional;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;

/**
 * `ArticleData` is published on both sides of `POST /api/articles`, and `author` is optional in the
 * body while the response guarantees it. A verb that resolved the response node would find `author`
 * already required and decline; this one has to edit the request node and leave the response alone.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Posting an article no longer requires `author`.')]
#[MadeRequestFieldOptional(schema: ArticleData::class, field: 'author')]
final class ArticleAuthorBecameOptional {}
