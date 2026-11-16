<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Workbench\App\Enums\ExportFault;
use Workbench\App\Exceptions\Relayed\ExportFaultResponseException;
use Workbench\App\Exceptions\Relayed\ExportLockedResponseException;
use Workbench\App\Exceptions\Relayed\ExportRefusedException;
use Workbench\App\Exceptions\Relayed\QuotaExceededException;
use Workbench\App\Exceptions\Relayed\RelayedResponseException;
use Workbench\App\Exceptions\Relayed\UpstreamRelayedException;

/**
 * Responses thrown inside an application's own subclass of `HttpResponseException`. The framework sends the
 * response the exception carries as it is, whichever class carries it.
 */
final class RelayedResponseController
{
    public function handedOn(): JsonResponse
    {
        throw new RelayedResponseException(response()->json(['error' => 'locked'], 423));
    }

    public function handedOnNamed(): JsonResponse
    {
        throw new RelayedResponseException(upstream: 'ledger', response: response()->json(['error' => 'locked'], 423));
    }

    public function builtItself(): JsonResponse
    {
        throw new ExportLockedResponseException;
    }

    public function twoLevelsHandedOn(): JsonResponse
    {
        throw new UpstreamRelayedException(response()->json(['error' => 'unavailable'], 503));
    }

    public function twoLevelsBuilt(): JsonResponse
    {
        throw new QuotaExceededException;
    }

    public function builtFromCase(): JsonResponse
    {
        throw new ExportFaultResponseException(ExportFault::Locked);
    }

    public function factory(): JsonResponse
    {
        throw ExportRefusedException::locked();
    }

    public function staticFactory(): JsonResponse
    {
        throw ExportRefusedException::gone();
    }
}
