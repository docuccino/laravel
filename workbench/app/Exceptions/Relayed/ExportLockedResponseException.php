<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions\Relayed;

use Illuminate\Http\Exceptions\HttpResponseException;

/** Builds the response it carries, so every throw of it is the same 423. */
final class ExportLockedResponseException extends HttpResponseException
{
    public function __construct(string $reason = 'locked')
    {
        parent::__construct(response()->json(['error' => $reason], 423));
    }
}
