<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Workbench\App\Enums\ExportFault;

/**
 * An export failure answered with its fault's backing value as the status.
 */
final class ExportFaultException extends HttpException
{
    public function __construct(public readonly ExportFault $fault)
    {
        parent::__construct($fault->value);
    }
}
