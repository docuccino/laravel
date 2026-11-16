<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Responses built into a local whose status or media type a later statement sets, on a line of its own:
 * a JSON, plain, streamed and redirect response, one thrown for the framework to send, one set on a single
 * branch, one set twice, one replaced after it was set, one handed to a helper that sets it, one handed to
 * a helper that sets only a header, and one set from the request. Only ever analysed, never dispatched.
 */
final class DeferredExportController
{
    /** Queue an export. */
    public function queue(): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        $response->setStatusCode(202);

        return $response;
    }

    /** Leave a note on the export queue. */
    public function note(): Response
    {
        $response = response('queued');
        $response->setStatusCode(202);

        return $response;
    }

    /** Stream an export as it is written. */
    public function stream(): StreamedResponse
    {
        $response = response()->stream(static function (): void {
            echo 'chunk';
        });
        $response->setStatusCode(202);

        return $response;
    }

    /** Send the client on to the export list. */
    public function forward(): RedirectResponse
    {
        $response = redirect('/exports');
        $response->setStatusCode(303);

        return $response;
    }

    /** Queue an export, deferring it when asked to. */
    public function defer(Request $request): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        if ($request->boolean('defer')) {
            $response->setStatusCode(202);
        }

        return $response;
    }

    /** Retry a failed export. */
    public function retry(): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        $response->setStatusCode(201);
        $response->setStatusCode(202);

        return $response;
    }

    /** Start an export over. */
    public function restart(): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        $response->setStatusCode(202);
        $response = response()->json(['created' => true], 201);

        return $response;
    }

    /** Hand an export to the queue. */
    public function hand(): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        $this->accept($response);

        return $response;
    }

    /** Queue an export, stamped with the API version that queued it. */
    public function stamp(): JsonResponse
    {
        $response = response()->json(['queued' => true], 202);
        $this->version($response);

        return $response;
    }

    /** Relay an export's status from upstream. */
    public function relay(Request $request): JsonResponse
    {
        $response = response()->json(['queued' => true]);
        $response->setStatusCode($request->integer('status'));

        return $response;
    }

    /** Download an export as CSV. */
    public function csv(): Response
    {
        $response = response('id,total', 202);
        $response->header('Content-Type', 'text/csv');

        return $response;
    }

    /** Refuse an export that is already running. */
    public function refuse(): never
    {
        $response = response()->json(['queued' => false]);
        $response->setStatusCode(409);

        throw new HttpResponseException($response);
    }

    private function accept(JsonResponse $response): void
    {
        $response->setStatusCode(202);
    }

    private function version(JsonResponse $response): void
    {
        $response->header('X-Api-Version', '2');
    }
}
