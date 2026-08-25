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
 * `#[Hidden]` (property- and class-level), an `Optional` marker, a spatie validation attribute, a
 * nested Data reference, and two free-form maps carrying an `@example` — one a populated object literal,
 * one the empty `{}` that was refused as untypable and dropped. It is only ever reflected — never
 * instantiated.
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
        /**
         * Whatever the publishing system stored alongside the article. Which keys appear depends on
         * where the article came from, so the value is not typed.
         *
         * @var array<string, mixed>
         *
         * @example {"source": "syndication", "wordCount": 1200}
         */
        public array $metadata,
        /**
         * Per-article rendering overrides. Empty unless an editor set one, which is why the example is
         * an empty object.
         *
         * @var array<string, mixed>
         *
         * @example {}
         */
        public array $overrides,
    ) {}
}
