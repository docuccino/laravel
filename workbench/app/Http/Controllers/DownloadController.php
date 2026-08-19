<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The file corner of a Laravel app: an action that hands back a file rather than a JSON body. Only ever
 * analysed, never dispatched, so the file it names does not have to exist.
 */
final class DownloadController
{
    /** The stock file-download signature — the class says nothing, the call says everything. */
    public function invoice(): BinaryFileResponse
    {
        return response()->download(storage_path('app/exports/invoices.pdf'), 'invoices.pdf');
    }
}
