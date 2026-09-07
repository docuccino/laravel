<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RequestVerbOrder;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeRequestFieldOptional;
use Docuccino\Attributes\Versioning\RenamedRequestField;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;

/**
 * The verb-order rule on the REQUEST half of the wire: one change that both stops demanding a field and
 * renames it. `MadeRequestFieldOptional` names `subtitle` as the code spells it today, so the rename has
 * to run after it or the required verb looks for a field that has already become `caption`.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'An article may omit `subtitle`, which was submitted as `caption`.')]
#[MadeRequestFieldOptional(schema: ArticleData::class, field: 'subtitle')]
#[RenamedRequestField(schema: ArticleData::class, from: 'caption', to: 'subtitle')]
final class ArticleCaptionStoppedBeingDemanded {}
