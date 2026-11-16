<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Workbench\App\Enums\ExportFault;
use Workbench\App\Exceptions\ExportFaultException;
use Workbench\App\Exceptions\ExportLockedException;

/**
 * Requests refused through `throw_if()` and `throw_unless()`, each in an action that otherwise answers with a
 * 200. Laravel throws the exception it is handed, builds one from a class name and the parameters after it,
 * calls a closure for one, or makes a `RuntimeException` of a message. Some guards, `abort_if()` among them,
 * are handed a condition they never throw on, and `report_if()` throws nothing.
 */
final class ThrowIfController
{
    /** Exports are never read-only here, so no guard on it can throw. */
    private const READ_ONLY = false;

    public function ifInstance(Request $request): JsonResponse
    {
        throw_if($request->boolean('busy'), new HttpException(409, 'The export is busy.'));

        return response()->json(['ok' => true]);
    }

    public function unlessInstance(Request $request): JsonResponse
    {
        throw_unless($request->boolean('open'), new ExportLockedException(423, 'The export is locked.'));

        return response()->json(['ok' => true]);
    }

    public function ifClassString(Request $request): JsonResponse
    {
        throw_if($request->boolean('locked'), ExportLockedException::class, 423, 'The export is locked.');

        return response()->json(['ok' => true]);
    }

    public function unlessClassString(Request $request): JsonResponse
    {
        throw_unless($request->boolean('open'), HttpException::class, 409, 'The export is busy.');

        return response()->json(['ok' => true]);
    }

    public function ifNamedParameter(Request $request): JsonResponse
    {
        throw_if($request->boolean('locked'), ExportLockedException::class, statusCode: 423);

        return response()->json(['ok' => true]);
    }

    public function ifMessage(Request $request): JsonResponse
    {
        throw_if($request->boolean('broken'), 'The export is broken.');

        return response()->json(['ok' => true]);
    }

    public function ifEnumInstance(Request $request): JsonResponse
    {
        throw_if($request->boolean('locked'), new ExportFaultException(ExportFault::Locked));

        return response()->json(['ok' => true]);
    }

    public function ifEnumClassString(Request $request): JsonResponse
    {
        throw_if($request->boolean('gone'), ExportFaultException::class, ExportFault::Gone);

        return response()->json(['ok' => true]);
    }

    public function ifClosure(Request $request): JsonResponse
    {
        throw_if($request->boolean('gone'), fn () => new HttpException(410));

        return response()->json(['ok' => true]);
    }

    public function ifCarried(Request $request): JsonResponse
    {
        throw_if($request->boolean('slow'), new HttpResponseException(response()->json(['error' => 'slow down'], 429)));

        return response()->json(['ok' => true]);
    }

    public function ifChosen(Request $request, int $status): JsonResponse
    {
        throw_if($request->boolean('refuse'), new HttpException($status));

        return response()->json(['ok' => true]);
    }

    public function declaredGuard(Request $request): JsonResponse
    {
        $this->refuseBusy($request->boolean('busy'));

        return response()->json(['ok' => true]);
    }

    public function ifFalse(): JsonResponse
    {
        throw_if(false, new HttpException(409));

        return response()->json(['ok' => true]);
    }

    public function ifReadOnly(): JsonResponse
    {
        throw_if(self::READ_ONLY, new HttpException(503));

        return response()->json(['ok' => true]);
    }

    public function abortIfFalse(): JsonResponse
    {
        abort_if(false, 403);

        return response()->json(['ok' => true]);
    }

    public function abortIfReadOnly(): JsonResponse
    {
        abort_if(self::READ_ONLY, 503);

        return response()->json(['ok' => true]);
    }

    public function reportIf(Request $request): JsonResponse
    {
        report_if($request->boolean('busy'), new HttpException(409));

        return response()->json(['ok' => true]);
    }

    /**
     * @throws HttpException
     */
    private function refuseBusy(bool $busy): void
    {
        throw_if($busy, HttpException::class, 409, 'The export is busy.');
    }
}
