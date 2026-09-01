<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\OptionalRequestPair;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeRequestFieldOptional;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;

/**
 * Two fields of one request body, written author first. Its twin in `OptionalRequestPairReversed` writes the same
 * pair the other way round, and the two documents have to come out identical — a `required` list whose
 * order depended on which verb ran first would be a member two derivations of one codebase disagree
 * about while agreeing on every fact in it.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Posting an article no longer requires `author` or `subtitle`.')]
#[MadeRequestFieldOptional(schema: ArticleData::class, field: 'author')]
#[MadeRequestFieldOptional(schema: ArticleData::class, field: 'subtitle')]
final class ArticleAuthorAndSubtitleBecameOptional {}
