<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/** Takes its status from a trait of the application's, not from spatie's concern. */
final class TraitStatusData extends Data
{
    use CalculatesAcceptedStatus;

    public function __construct(
        public string $id,
    ) {}
}
