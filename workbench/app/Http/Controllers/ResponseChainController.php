<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Workbench\App\Http\Responses\GoneJsonResponse;
use Workbench\App\Http\Responses\MovedResponse;
use Workbench\App\Http\Responses\PinnedStatusJsonResponse;

/**
 * Fluent chains that set a response's content, data, callback, encoding, a cookie or cache headers, or call
 * a setter nothing documents; a download deleted once it is sent and a redirect flashing to the session; chains on an application's response subclass, one of which overrides the status
 * setter; and a response built with `new` into a local, returned as it is or after its data is set. Only
 * ever analysed, never dispatched.
 */
final class ResponseChainController
{
    /** Accept a note. */
    public function note(): Response
    {
        return response('queued', 202)->setContent('accepted');
    }

    /** Accept an export. */
    public function accept(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->setData(['accepted' => true]);
    }

    /** Accept an export, setting the status last. */
    public function settle(): JsonResponse
    {
        return response()->json(['queued' => true])->setData(['accepted' => true])->setStatusCode(202);
    }

    /** Queue an export, answering JSONP when asked. */
    public function padded(Request $request): JsonResponse
    {
        return response()->json(['queued' => true], 202)->setCallback($request->query('callback'));
    }

    /** Queue a batch, its id map always an object. */
    public function batch(): JsonResponse
    {
        return response()->json(['ids' => []], 202)->setEncodingOptions(JSON_FORCE_OBJECT);
    }

    /** Queue an export, pretty-printed. */
    public function pretty(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->setEncodingOptions(JSON_PRETTY_PRINT);
    }

    /** Queue an export and remember it. */
    public function remember(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->cookie('queued', '1');
    }

    /** Check an export is unchanged. */
    public function unchanged(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->setNotModified();
    }

    /** Queue an export, cacheable for a minute. */
    public function cached(): JsonResponse
    {
        return response()->json(['queued' => true], 202)->setMaxAge(60)->setEtag('queued')->setPublic();
    }

    /** Download the latest export, deleted once it is sent. */
    public function export(): BinaryFileResponse
    {
        return response()->download(storage_path('exports/latest.csv'), 'latest.csv')->deleteFileAfterSend();
    }

    /** Queue an export and see the export list. */
    public function flash(): RedirectResponse
    {
        return redirect('/exports', 303)->with('status', 'queued');
    }

    /** Queue an export, prepared for the request. */
    public function prepared(Request $request): JsonResponse
    {
        return response()->json(['queued' => true], 202)->prepare($request);
    }

    /** Look up a removed export. */
    public function removed(): GoneJsonResponse
    {
        return (new GoneJsonResponse)->setStatusCode(404);
    }

    /** Follow a moved export. */
    public function relocated(): MovedResponse
    {
        return (new MovedResponse('/exports'))->setStatusCode(308);
    }

    /** Look up a locked export. */
    public function locked(): PinnedStatusJsonResponse
    {
        return (new PinnedStatusJsonResponse)->setStatusCode(404);
    }

    /** Queue an export, built into a local. */
    public function built(): JsonResponse
    {
        $response = new JsonResponse(['queued' => true], 202);

        return $response;
    }

    /** Accept an export, built into a local whose data is set after. */
    public function refilled(): JsonResponse
    {
        $response = new JsonResponse(['queued' => true], 202);
        $response->setData(['accepted' => true]);

        return $response;
    }
}
