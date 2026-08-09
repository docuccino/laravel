<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use Docuccino\Attributes\CaseDescription;

/**
 * An int-backed workbench enum exercising the integer branch of the enum integration: the backing
 * values become an `integer`-typed `enum`, and `#[CaseDescription]` prose still maps by value.
 */
enum WidgetPriority: int
{
    #[CaseDescription('Handled when idle.')]
    case Low = 1;

    case Normal = 5;

    #[CaseDescription('Jumps the queue.')]
    case High = 10;
}
