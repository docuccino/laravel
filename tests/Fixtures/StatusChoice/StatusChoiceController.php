<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\StatusChoice;

use Docuccino\Attributes\Response;
use Docuccino\Attributes\ResponseHeader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A status chosen between constants in one expression — `$ok ? 200 : 503` — in each place a response takes
 * its status, beside the same endpoint written as two returns; a status nothing can read, bare and with the
 * statuses it sends named in each way a declaration can name one; and an empty response whose status is a
 * choice.
 */
final class StatusChoiceController
{
    public function chained(Request $request): JsonResponse
    {
        $ok = $request->boolean('ready');

        return response()->json(['ok' => $ok])->setStatusCode($ok ? 200 : 503);
    }

    public function branches(Request $request): JsonResponse
    {
        if ($request->boolean('ready')) {
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false], 503);
    }

    public function upsert(Request $request): JsonResponse
    {
        return (new StatusChoiceResource((object) []))->response()->setStatusCode($request->boolean('fresh') ? 201 : 200);
    }

    public function requested(Request $request): JsonResponse
    {
        return response()->json(['ok' => true])->setStatusCode($request->integer('code'));
    }

    #[Response(status: 200)]
    #[Response(status: 503)]
    public function named(Request $request): JsonResponse
    {
        return response()->json(['ok' => true])->setStatusCode($request->integer('code'));
    }

    public function emptied(Request $request): JsonResponse
    {
        return response()->noContent($request->boolean('reset') ? 205 : 204);
    }

    #[ResponseHeader(name: 'X-Request-Id', status: 202)]
    public function namedHeader(Request $request): JsonResponse
    {
        return response()->json(['ok' => true])->setStatusCode($request->integer('code'));
    }

    #[Response(status: 503)]
    public function namedError(Request $request): JsonResponse
    {
        return response()->json(['ok' => true])->setStatusCode($request->integer('code'));
    }

    #[Response(status: 204)]
    public function namedEmpty(Request $request): JsonResponse
    {
        return response()->json(['ok' => true])->setStatusCode($request->integer('code'));
    }
}
