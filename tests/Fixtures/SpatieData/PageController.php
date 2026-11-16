<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Attributes\Response;

/** Pages that are their own envelope, returned and declared. */
final class PageController
{
    public function index(): AuthorPageData
    {
        return AuthorPageData::page([new AuthorData('a', 'a@example.com')], new PageMetaData(1, 1));
    }

    #[Response(status: 200, type: AuthorPageData::class)]
    public function declared(): mixed
    {
        return AuthorPageData::page([new AuthorData('a', 'a@example.com')], new PageMetaData(1, 1));
    }
}
