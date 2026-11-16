<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions\Relayed;

/** Two levels below the framework, building the response it relays itself. */
final class QuotaExceededException extends RelayedResponseException
{
    public function __construct()
    {
        parent::__construct(response()->json(['error' => 'quota_exceeded'], 429), 'quota');
    }
}
