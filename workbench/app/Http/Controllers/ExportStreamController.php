<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams, files and redirects, each sent with the status its call names, beside one stream sent with the
 * framework's 200, one redirect with the framework's 302, one of each whose status nothing can read, and two
 * streams whose headers and name are passed by argument name. Only ever analysed, never dispatched.
 */
final class ExportStreamController
{
    /** Start a CSV export. */
    public function queue(): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo "id,total\n";
        }, 202, ['Content-Type' => 'text/csv']);
    }

    /** Stream the rows of an export, a page at a time. */
    public function rows(): StreamedJsonResponse
    {
        return response()->streamJson(['rows' => []], 206);
    }

    /** Stream the rows of an export, or the part asked for. */
    public function slice(bool $partial): StreamedJsonResponse
    {
        return response()->streamJson(['rows' => []], $partial ? 206 : 200);
    }

    /** Fetch part of an export's report. */
    public function report(): BinaryFileResponse
    {
        return new BinaryFileResponse(storage_path('exports/report.pdf'), 206);
    }

    /** Stream a finished CSV export. */
    public function export(): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo "id,total\n";
        }, 200, ['Content-Type' => 'text/csv']);
    }

    /** Relay an export with the status the caller asks for. */
    public function relay(Request $request): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo "id,total\n";
        }, $request->integer('status'), ['Content-Type' => 'text/csv']);
    }

    /** Find an export that moved. */
    public function moved(): RedirectResponse
    {
        return redirect()->to('/api/exports/archive', 301);
    }

    /** Hand a finished export back. */
    public function finished(): RedirectResponse
    {
        return back(303);
    }

    /** Find the latest export. */
    public function latest(): RedirectResponse
    {
        return redirect('/api/exports/latest');
    }

    /** Follow an export wherever the caller asks. */
    public function follow(Request $request): RedirectResponse
    {
        return redirect()->to('/api/exports/latest', $request->integer('status'));
    }

    /** Stream a CSV export as it is written. */
    public function named(): StreamedResponse
    {
        return response()->stream(static function (): void {
            echo "id,total\n";
        }, headers: ['Content-Type' => 'text/csv']);
    }

    /** Download the ledger as CSV. */
    public function ledger(): StreamedResponse
    {
        return Response::streamDownload(static function (): void {
            echo "id,total\n";
        }, name: 'ledger.csv', headers: ['Content-Type' => 'text/csv']);
    }
}
