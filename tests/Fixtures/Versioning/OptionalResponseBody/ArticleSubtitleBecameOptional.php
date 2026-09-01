<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OptionalResponseBody;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeResponseFieldOptional;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;

/** The mirror of the request verb over the same class: the response node moves and the request one does not. */
#[ApiVersionChange(since: '2026-09-01', description: 'An article omits `subtitle` where it has none; before this it was always sent.')]
#[MadeResponseFieldOptional(schema: ArticleData::class, field: 'subtitle')]
final class ArticleSubtitleBecameOptional {}
