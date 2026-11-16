<?php

declare(strict_types=1);

namespace Workbench\App\Http\Responses;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Workbench\App\Enums\ExportFault;

/** A stream sent with the status of whichever export fault its caller hands it. */
class FaultStreamResponse extends StreamedResponse
{
    public function __construct(callable $callback, ExportFault $fault)
    {
        parent::__construct($callback, $fault->value);
    }
}
