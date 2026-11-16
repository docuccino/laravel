<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Illuminate\Http\JsonResponse;
use Workbench\App\Enums\ExportFault;
use Workbench\App\Exceptions\ExportLockedException;

// The application's namespaced helpers: a guard, one that refuses with what that guard throws, and two
// response functions, one answering with its own status and one with the case it is handed.

function neverCustom(): never
{
    throw new ExportLockedException(423, 'The export is locked.');
}

function neverNested(): never
{
    neverCustom();
}

function gone(): JsonResponse
{
    return response()->json(['error' => 'gone'], 410);
}

function fault(ExportFault $fault): JsonResponse
{
    return new JsonResponse(['error' => $fault->name], $fault->value);
}
