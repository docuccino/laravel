<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\InDocs;

use Docuccino\Attributes\InDocs;
use Illuminate\Http\JsonResponse;

/**
 * A controller pinned to a document that exists, with one action naming a second key that does not: the
 * route is still in the `admin` document, so the dead key is a dead key rather than a route lost.
 */
#[InDocs('admin')]
final class PinnedController
{
    public function index(): JsonResponse
    {
        return new JsonResponse([]);
    }

    #[InDocs('admin', 'reprts')]
    public function show(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
