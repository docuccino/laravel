<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Data;

/** A van whose own `collect()` decides what each item is built from, whatever it is handed. */
final class CollectingVanData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $plate,
    ) {}

    /** @return array<int, static> */
    public static function collect(mixed $items, ?string $into = null): array
    {
        return [self::from(['id' => 1, 'plate' => 'DEMO'])];
    }
}
