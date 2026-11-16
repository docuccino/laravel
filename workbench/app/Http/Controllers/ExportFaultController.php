<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workbench\App\Enums\ExportFault;

/**
 * Export faults answered with the fault's backing value as the status: through a helper handed the fault,
 * built with `new JsonResponse()` and with `response()->json()`, and with the fault chosen at run time.
 */
final class ExportFaultController
{
    /** Report an export that is gone. */
    public function gone(string $export): JsonResponse
    {
        return $this->answer(ExportFault::Gone);
    }

    /** Report an export that is locked. */
    public function locked(string $export): JsonResponse
    {
        return $this->answerJson(ExportFault::Locked);
    }

    /** Report the fault the request names. */
    public function report(Request $request, string $export): JsonResponse
    {
        $fault = $request->boolean('locked') ? ExportFault::Locked : ExportFault::Gone;

        return new JsonResponse(['error' => $fault->name], $fault->value);
    }

    private function answer(ExportFault $fault): JsonResponse
    {
        return new JsonResponse(['error' => $fault->name], $fault->value);
    }

    private function answerJson(ExportFault $fault): JsonResponse
    {
        return response()->json(['error' => $fault->name], $fault->value);
    }
}
