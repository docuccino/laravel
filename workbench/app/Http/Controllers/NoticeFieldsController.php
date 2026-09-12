<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Workbench\App\Http\Requests\SearchNoticesRequest;
use Workbench\App\Http\Requests\StoreNoticeRequest;

/**
 * The two verbs a recovered rule set lands in two different halves of the document under, each with a
 * declaration standing down the note for one field and nothing standing it down for its neighbour.
 */
final class NoticeFieldsController
{
    public function store(StoreNoticeRequest $request): JsonResponse
    {
        return response()->json([], 201);
    }

    #[QueryParameter(name: 'marker', type: 'string')]
    #[QueryParameter(name: 'scope', type: 'object')]
    public function index(SearchNoticesRequest $request): JsonResponse
    {
        return response()->json([]);
    }
}
