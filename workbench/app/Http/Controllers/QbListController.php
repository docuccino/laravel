<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Workbench\App\Models\Form;
use Workbench\App\Support\ListQueryBuilder;

/**
 * The flagship Query Builder list endpoint: a QB SUBCLASS ({@see ListQueryBuilder}) filtered/sorted
 * via `allowedFilters`/`allowedSorts` and paginated through a CUSTOM terminal (`paginateList`,
 * declared in `integrations.query_builder.pagination_terminals`), returning a resource collection.
 * Its golden pins the whole shape end-to-end: the recovered filter/sort query params, the custom
 * terminal's page/per_page params, the strict-mode 400, AND — the previously-missing QB 200 — the
 * `{data, links, meta}` collection envelope (the custom terminal triggers it, arch PIN 3 / D3). The
 * stub engine scripts the trace + return; the body is inert.
 */
final class QbListController
{
    /**
     * @return AnonymousResourceCollection<int, ArticleResource>
     */
    public function index(): AnonymousResourceCollection
    {
        return ArticleResource::collection(
            ListQueryBuilder::for(Form::class)
                ->allowedFilters(['name'])
                ->allowedSorts(['name'])
                ->paginateList(20)
        );
    }
}
