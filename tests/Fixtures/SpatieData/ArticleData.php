<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;
use Spatie\LaravelData\Attributes\Hidden as SpatieHidden;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * A Data fixture exercising the whole spatie surface the integration reads: `#[SchemaName]`/
 * `#[SchemaId]` component identity, a `#[MapName]` input+output rename, spatie's and Docuccino's
 * `#[Hidden]` (property- and class-level), an `Optional` marker, a spatie validation attribute, and a
 * nested Data reference. It is only ever reflected — never instantiated.
 */
#[SchemaName('Article')]
#[SchemaId('article.v1')]
#[DocuccinoHidden('internal')]
final class ArticleData extends Data
{
    public function __construct(
        public int $id,
        #[MapName('heading', 'headline')]
        public string $title,
        #[Max(500)]
        public string $body,
        #[SpatieHidden]
        public string $secret,
        public int $internal,
        public string|Optional $subtitle,
        public ?AuthorData $author,
    ) {}
}
