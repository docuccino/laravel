<?php

declare(strict_types=1);

namespace Workbench\App\Data;

use Workbench\App\Enums\WidgetStatus;

/**
 * The data object named by `#[Response(type: WidgetData::class)]` on the widget routes. Its `status`
 * property exercises the enum integration (backing values + `#[CaseDescription]`).
 */
final class WidgetData
{
    public function __construct(
        public int $id,
        public string $name,
        public WidgetStatus $status,
    ) {}
}
