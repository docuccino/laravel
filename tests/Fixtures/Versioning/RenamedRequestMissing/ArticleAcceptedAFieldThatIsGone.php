<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedRequestMissing;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedRequestField;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;

/**
 * A request rename naming the field the way the RESPONSE spells it. The document plainly publishes a
 * request body for this class and a field called `headline` somewhere in it, so the report has to say
 * which of the two shapes it looked in or the reader will go hunting for a bug that is not there.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'An article is submitted with `headline` where it was submitted with `name`.')]
#[RenamedRequestField(schema: ArticleData::class, from: 'name', to: 'headline')]
final class ArticleAcceptedAFieldThatIsGone {}
