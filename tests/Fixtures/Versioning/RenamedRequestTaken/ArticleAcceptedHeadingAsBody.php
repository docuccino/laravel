<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedRequestTaken;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedRequestField;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;

/** A request rename onto a name the request body already accepts, which would collapse two fields into one. */
#[ApiVersionChange(since: '2026-09-01', description: 'An article is submitted with `heading` where it was submitted with `body`.')]
#[RenamedRequestField(schema: ArticleData::class, from: 'body', to: 'heading')]
final class ArticleAcceptedHeadingAsBody {}
