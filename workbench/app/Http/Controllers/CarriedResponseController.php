<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Workbench\App\Enums\ExportFault;
use Workbench\App\Http\Responses\GoneJsonResponse;

/**
 * Responses an action hands the framework inside an exception rather than returning them: thrown in an
 * `HttpResponseException` built each way a response is, thrown from a helper, and handed to `abort()`. The
 * framework sends each carried response as it is.
 */
final class CarriedResponseController
{
    public function helperJson(): JsonResponse
    {
        throw new HttpResponseException(response()->json(['error' => 'locked'], 423));
    }

    public function facadeJson(): JsonResponse
    {
        throw new HttpResponseException(Response::json(['error' => 'locked'], 423));
    }

    public function newJson(): JsonResponse
    {
        throw new HttpResponseException(new JsonResponse(['error' => 'locked'], 423));
    }

    public function text(): JsonResponse
    {
        throw new HttpResponseException(response('Already submitted.', 409));
    }

    public function redirect(): JsonResponse
    {
        throw new HttpResponseException(redirect('/login'));
    }

    public function subclass(): JsonResponse
    {
        throw new HttpResponseException(new GoneJsonResponse);
    }

    public function viaHelper(): JsonResponse
    {
        $this->refuse();

        return response()->json(['ok' => true]);
    }

    public function caseHelper(): JsonResponse
    {
        throw new HttpResponseException($this->faulted(ExportFault::Locked));
    }

    public function caseChosen(Request $request): JsonResponse
    {
        $fault = $request->boolean('locked') ? ExportFault::Locked : ExportFault::Gone;

        throw new HttpResponseException(response()->json(['error' => $fault->name], $fault->value));
    }

    public function abortJson(): JsonResponse
    {
        abort(response()->json(['error' => 'locked'], 423));
    }

    public function abortFacade(): JsonResponse
    {
        abort(Response::json(['error' => 'locked'], 423));
    }

    public function abortIf(Request $request): JsonResponse
    {
        abort_if($request->boolean('locked'), response()->json(['error' => 'locked'], 423));

        return response()->json(['ok' => true]);
    }

    private function refuse(): void
    {
        throw new HttpResponseException(response()->json(['error' => 'locked'], 423));
    }

    private function faulted(ExportFault $fault): JsonResponse
    {
        return response()->json(['error' => $fault->name], $fault->value);
    }
}
