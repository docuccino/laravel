<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/** A van whose plate is read from a nested input key, which no model attribute is named. */
final class NestedInputVanData extends Data
{
    public function __construct(
        public readonly int $id,
        #[MapInputName('registration.plate')]
        public readonly string $plate,
    ) {}
}
