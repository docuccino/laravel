<?php

declare(strict_types=1);

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Workbench\App\Enums\ExportFault;
use Workbench\App\Exceptions\ExportLockedException;

// The application's global helpers, each declared only where nothing has declared the name already.

if (! function_exists('export_conflict')) {
    function export_conflict(): void
    {
        throw new HttpException(409, 'The export already exists.');
    }
}

if (! function_exists('export_missing')) {
    function export_missing(): never
    {
        abort(404);
    }
}

if (! function_exists('export_locked')) {
    function export_locked(): void
    {
        throw new ExportLockedException(423, 'The export is locked.');
    }
}

if (! function_exists('export_name_taken')) {
    function export_name_taken(): never
    {
        throw ValidationException::withMessages(['name' => 'The name is taken.']);
    }
}

if (! function_exists('export_throttled')) {
    function export_throttled(): void
    {
        throw new HttpResponseException(response()->json(['error' => 'slow down'], 429));
    }
}

if (! function_exists('export_gone')) {
    function export_gone(): JsonResponse
    {
        return response()->json(['error' => 'gone'], 410);
    }
}

if (! function_exists('export_fault')) {
    function export_fault(ExportFault $fault): JsonResponse
    {
        return new JsonResponse(['error' => $fault->name], $fault->value);
    }
}
