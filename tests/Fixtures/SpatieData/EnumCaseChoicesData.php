<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\NotIn;
use Spatie\LaravelData\Data;
use Workbench\App\Enums\FormChannel;
use Workbench\App\Enums\WidgetPriority;
use Workbench\App\Enums\WidgetStatus;

/** Choice attributes naming enum cases where a value goes, and an enum's class on a string property. */
final class EnumCaseChoicesData extends Data
{
    public function __construct(
        #[In(WidgetStatus::Draft, 'scheduled')] public string $schedule,
        #[In([WidgetPriority::Low, WidgetPriority::High])] public int $priority,
        #[In(FormChannel::Email)] public string $channel,
        #[NotIn(WidgetStatus::Archived)] public string $phase,
        #[Enum(WidgetStatus::class)] public string $status,
    ) {}
}
