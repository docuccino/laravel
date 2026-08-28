<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\InDocs;

use Docuccino\Attributes\InDocs;
use Illuminate\Http\JsonResponse;

/**
 * A controller pinned to a document key nobody configured. `#[InDocs]` is read down the class, so both
 * actions below are excluded from every document by this one word — which is the whole reason the
 * report is per KEY and not per route.
 */
#[InDocs('admn')]
final class MisspelledController
{
    public function index(): JsonResponse
    {
        return new JsonResponse([]);
    }

    public function show(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
