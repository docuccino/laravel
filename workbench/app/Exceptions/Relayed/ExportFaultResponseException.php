<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions\Relayed;

use Illuminate\Http\Exceptions\HttpResponseException;
use Workbench\App\Enums\ExportFault;

/** Builds the response it carries from the fault it is handed, whose value is the status. */
final class ExportFaultResponseException extends HttpResponseException
{
    public function __construct(ExportFault $fault)
    {
        parent::__construct(response()->json(['error' => $fault->name], $fault->value));
    }
}
