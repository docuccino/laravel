<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Workbench\App\Http\Requests\PageSizeBoundsRequest;
use Workbench\App\Models\Form;
use Workbench\App\Support\ListPageSize;

/**
 * List endpoints paging one collection, differing only in what the size handed to `paginate()` was built
 * from: a request key or a helper's own literal, which only the helper's body tells apart, and two direct
 * reads under a FormRequest bounding the key. The stub engine scripts the trace; the bodies are inert.
 */
final class PagedListController
{
    /**
     * The shared clamp: the read is the value the helper answers with, under a key of the app's choosing.
     *
     * @return AnonymousResourceCollection<int, ArticleResource>
     */
    public function clamped(Request $request): AnonymousResourceCollection
    {
        return ArticleResource::collection(Form::query()->paginate(ListPageSize::clamp($request)));
    }

    /**
     * The same clamp with the read named in a local first, and its literal fallback.
     *
     * @return AnonymousResourceCollection<int, ArticleResource>
     */
    public function limited(Request $request): AnonymousResourceCollection
    {
        return ArticleResource::collection(Form::query()->paginate(ListPageSize::limit($request)));
    }

    /**
     * A preset selector: the key picks which fixed size is used, and is not itself a size.
     *
     * @return AnonymousResourceCollection<int, ArticleResource>
     */
    public function preset(Request $request): AnonymousResourceCollection
    {
        return ArticleResource::collection(Form::query()->paginate(ListPageSize::preset($request)));
    }

    /**
     * A key read inside a closure the helper never calls, so the size it answers with never touches it.
     *
     * @return AnonymousResourceCollection<int, ArticleResource>
     */
    public function lazy(Request $request): AnonymousResourceCollection
    {
        return ArticleResource::collection(Form::query()->paginate(ListPageSize::lazy($request)));
    }

    /**
     * A fallback past the bound the FormRequest validates: an absent key is served 50, a sent one at most 20.
     *
     * @return AnonymousResourceCollection<int, ArticleResource>
     */
    public function overBound(PageSizeBoundsRequest $request): AnonymousResourceCollection
    {
        return ArticleResource::collection(Form::query()->paginate($request->integer('per_page', 50)));
    }

    /**
     * The same bound with a fallback inside it.
     *
     * @return AnonymousResourceCollection<int, ArticleResource>
     */
    public function withinBound(PageSizeBoundsRequest $request): AnonymousResourceCollection
    {
        return ArticleResource::collection(Form::query()->paginate($request->integer('per_page', 15)));
    }
}
