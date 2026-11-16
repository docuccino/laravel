<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Data;

/** A van whose plate falls back to a default where `from()` finds none, so the default may be what is sent. */
final class DefaultedVanData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $plate = 'UNREGISTERED',
    ) {}
}
