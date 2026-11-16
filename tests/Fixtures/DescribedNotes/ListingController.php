<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DescribedNotes;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;

/** The same rules read as query parameters and as a body, described by their author and not. */
final class ListingController
{
    #[QueryParameter('until', description: 'End of the range.')]
    #[QueryParameter('stage', description: 'The stage to list.')]
    #[QueryParameter('nickname', description: 'What the lister goes by.')]
    public function index(ListingRequest $request): JsonResponse
    {
        return new JsonResponse([]);
    }

    public function search(ListingRequest $request): JsonResponse
    {
        return new JsonResponse([]);
    }

    #[BodyParameter('until', description: 'End of the range.')]
    #[BodyParameter('name', description: 'Who to look for.')]
    public function store(ListingRequest $request): JsonResponse
    {
        return new JsonResponse([], 201);
    }

    public function create(TypeDescribedListingRequest $request): JsonResponse
    {
        return new JsonResponse([], 201);
    }
}
