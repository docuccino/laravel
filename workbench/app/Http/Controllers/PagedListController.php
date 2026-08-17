<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Laravel\Tests\Fixtures\ApiResources\ArticleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Workbench\App\Models\Form;
use Workbench\App\Support\ListPageSize;

/**
 * Four list endpoints that page the same collection, differing only in what the size handed to
 * `paginate()` was built from. Two of them take it from a request key and two answer with a literal of the
 * helper's own, and only the helper's body says which — so the golden they emit is where "the key is the
 * size" is either proven or absent. The stub engine scripts the trace + the return; the bodies are inert.
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
}
