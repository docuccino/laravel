<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workbench\App\Enums\ExportFault;

use function Workbench\App\Support\fault;
use function Workbench\App\Support\gone;
use function Workbench\App\Support\neverNested;

/**
 * Helpers an application writes as functions, global ones from its own `helpers.php` and namespaced ones
 * from `Workbench\App\Support`: guards refusing a request the action otherwise answers with a 200, and
 * responses an action returns. PHP runs a function's body at the call as it runs a method's.
 */
final class FunctionHelperController
{
    public function globalHttp(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            export_conflict();
        }

        return response()->json(['ok' => true]);
    }

    public function globalAbort(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            export_missing();
        }

        return response()->json(['ok' => true]);
    }

    public function globalCustom(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            export_locked();
        }

        return response()->json(['ok' => true]);
    }

    public function globalValidation(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            export_name_taken();
        }

        return response()->json(['ok' => true]);
    }

    public function globalCarried(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            export_throttled();
        }

        return response()->json(['ok' => true]);
    }

    public function globalGone(): JsonResponse
    {
        return export_gone();
    }

    public function globalFault(): JsonResponse
    {
        return export_fault(ExportFault::Gone);
    }

    public function namespacedNested(Request $request): JsonResponse
    {
        if ($request->boolean('refuse')) {
            neverNested();
        }

        return response()->json(['ok' => true]);
    }

    public function namespacedGone(): JsonResponse
    {
        return gone();
    }

    public function namespacedFault(): JsonResponse
    {
        return fault(ExportFault::Locked);
    }
}
