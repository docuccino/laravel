<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * An export failure whose backing value is the HTTP status it answers with.
 */
enum ExportFault: int
{
    case Gone = 410;
    case Locked = 423;
}
