<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Docuccino\Attributes\HeaderParameter;
use Docuccino\Attributes\IgnoreParam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use Workbench\App\Http\Requests\PlaceOrderRequest;

/**
 * Actions that read request headers by name, in every spelling the framework offers. The stub engine
 * scripts each trace over these real bodies; nothing here is dispatched.
 */
final class RequestHeadersController
{
    /** The header is read inside the FormRequest, in a method this action calls. */
    public function store(PlaceOrderRequest $request): JsonResponse
    {
        return response()->json(['key' => $request->idempotencyKey()], 201);
    }

    /** Read inline, twice under two spellings, beside reads that name nothing a client can send. */
    public function trace(Request $request, string $dynamic): JsonResponse
    {
        $id = $request->header('X-Request-Id') ?? $request->headers->get('x-request-id');
        $fresh = RequestFacade::hasHeader('If-None-Match');
        $format = $request->header('Accept');
        $other = $request->header($dynamic);
        $version = $request->header('X-Api-Version');

        return response()->json(['id' => $id, 'fresh' => $fresh, 'format' => $format, 'other' => $other, 'version' => $version])
            ->header('X-Served-By', 'workbench');
    }

    /** The same header the attribute declares, read under another case: the declaration is what stands. */
    #[HeaderParameter(name: 'idempotency-key', type: 'string', format: 'uuid', required: true, description: 'Makes a retried request safe to repeat.')]
    public function pinned(Request $request): JsonResponse
    {
        return response()->json(['key' => $request->header('Idempotency-Key')]);
    }

    /** A header read here that the author keeps out of the document. */
    #[IgnoreParam(name: 'X-Internal-Trace', in: 'header')]
    public function quiet(Request $request): JsonResponse
    {
        return response()->json(['trace' => $request->header('X-Internal-Trace'), 'id' => $request->header('X-Request-Id')]);
    }
}
