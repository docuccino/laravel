<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Workbench\App\Enums\ExportFault;
use Workbench\App\Exceptions\ExportFaultException;

/**
 * Refusals whose fault is looked up from its backing value: from a value written in the code, from one no
 * fault is backed by, and from one the request chooses.
 */
final class ExportController
{
    /** Lock an export. */
    public function lock(string $export): JsonResponse
    {
        throw new ExportFaultException(ExportFault::from(423));
    }

    /** Fail an export with the fault the caller names. */
    public function fail(string $export, int $code): JsonResponse
    {
        throw new ExportFaultException(ExportFault::from($code));
    }

    /** Retire an export under a fault code no fault is backed by. */
    public function retire(string $export): JsonResponse
    {
        throw new ExportFaultException(ExportFault::from(999));
    }

    /** Withdraw an export, naming a fault code no fault is backed by. */
    public function withdraw(string $export): JsonResponse
    {
        abort(404, ExportFault::from(999)->name);
    }
}
