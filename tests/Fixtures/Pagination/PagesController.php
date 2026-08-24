<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Pagination;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * One paginated list endpoint per shape the page-of-X hoist has to answer for: each paginator kind,
 * one item type paginated twice, a second item type, and two item schemas nothing can name a page of.
 * The kind and the item type come from the scripted engine ({@see PaginationEngine}), not from this
 * source — the return type is the same `AnonymousResourceCollection` on every one of them, exactly as
 * it is in a real application.
 */
final class PagesController
{
    public function articles(): AnonymousResourceCollection
    {
        return AnonymousResourceCollection::make([]);
    }

    /** A second route paginating the item type `articles()` does — the two must share one component. */
    public function moreArticles(): AnonymousResourceCollection
    {
        return AnonymousResourceCollection::make([]);
    }

    public function simpleArticles(): AnonymousResourceCollection
    {
        return AnonymousResourceCollection::make([]);
    }

    public function cursorArticles(): AnonymousResourceCollection
    {
        return AnonymousResourceCollection::make([]);
    }

    /** A different item type, so two page components coexist without either naming the other. */
    public function authors(): AnonymousResourceCollection
    {
        return AnonymousResourceCollection::make([]);
    }

    /** An item type that is no class at all — nothing to derive a page's name from. */
    public function shapedItems(): AnonymousResourceCollection
    {
        return AnonymousResourceCollection::make([]);
    }

    /** A named item type the analyser cannot expand, so its schema never became a component. */
    public function unexpandable(): AnonymousResourceCollection
    {
        return AnonymousResourceCollection::make([]);
    }
}
