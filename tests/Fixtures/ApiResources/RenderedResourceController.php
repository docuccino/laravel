<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A resource handed back as the response the framework renders from it, beside the bare return each form
 * is equivalent to: `->response()`, `->toResponse($request)`, the status idiom `->response()->setStatusCode(201)`,
 * a freshly created model (also with a media type stamped on it), a collection with and without a
 * paginator, a named collection, and a resource whose own `toResponse()` answers a guard arm of its own.
 */
final class RenderedResourceController
{
    public function plain(): ReleaseResource
    {
        return new ReleaseResource((object) ['tag' => 'v1']);
    }

    public function response(): JsonResponse
    {
        return (new ReleaseResource((object) ['tag' => 'v1']))->response();
    }

    public function toResponse(Request $request): JsonResponse
    {
        return (new ReleaseResource((object) ['tag' => 'v1']))->toResponse($request);
    }

    public function created(): JsonResponse
    {
        return (new ReleaseResource((object) ['tag' => 'v1']))->response()->setStatusCode(201);
    }

    public function store(): JsonResponse
    {
        return (new ReleaseResource(Widget::create([])))->response();
    }

    public function restated(): JsonResponse
    {
        return (new ReleaseResource(Widget::create([])))->response()->setStatusCode(200);
    }

    public function collection(): JsonResponse
    {
        return ReleaseResource::collection([])->response();
    }

    public function paginated(): JsonResponse
    {
        return ReleaseResource::collection(Widget::query()->paginate(15))->response();
    }

    public function named(): JsonResponse
    {
        return (new ReleaseCollection([]))->response();
    }

    public function relabelled(): JsonResponse
    {
        return (new ReleaseResource(Widget::create([])))->response()->header('Content-Type', 'application/vnd.release+json');
    }

    public function guarded(): ReleaseResource
    {
        return new ReleaseResource((object) ['tag' => 'v1']);
    }
}
