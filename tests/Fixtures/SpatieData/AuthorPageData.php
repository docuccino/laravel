<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Data;

/**
 * A page of authors that is its own envelope, `{data, meta}`, so its named constructor hands it out
 * with spatie's root wrap taken off — under a global `data.wrap` it would otherwise be sent inside a
 * second `data`. The nested list is transformed bare, as an app does to keep it a plain array.
 */
final class AuthorPageData extends Data
{
    /**
     * @param  list<AuthorData>  $data
     */
    public function __construct(
        /** The authors on this page. */
        #[WithTransformer(UnwrapCollectionTransformer::class)]
        public array $data,
        /** Where this page sits in the whole list. */
        public PageMetaData $meta,
    ) {}

    /**
     * @param  list<AuthorData>  $authors
     */
    public static function page(array $authors, PageMetaData $meta): self
    {
        return (new self($authors, $meta))->withoutWrapping();
    }
}
