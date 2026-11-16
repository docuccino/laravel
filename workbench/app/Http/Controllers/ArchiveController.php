<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\Response;
use Workbench\App\Enums\ExportFault;

/**
 * Plain responses the factory makes, each sent with the status its call names: through the `response()`
 * helper, `response()->make()` and `response()->view()`, beside one sent with the framework's 200.
 */
final class ArchiveController
{
    /** Purge an archived entry. */
    public function purge(): Response
    {
        return response('', 410);
    }

    /** Retire an archived entry. */
    public function retire(): Response
    {
        return response('archived', Response::HTTP_GONE);
    }

    /** Lock an archived entry. */
    public function lock(): Response
    {
        return response()->make('', ExportFault::Locked->value);
    }

    /** Show the tombstone of an archived entry. */
    public function tombstone(): Response
    {
        return response()->view('archived', [], 410);
    }

    /** Check the archive is up. */
    public function ping(): Response
    {
        return response('created');
    }
}
