<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Data;

/** A van whose own `from()` decides what it is built from, whatever it is handed. */
final class OverridingVanData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $plate,
    ) {}

    public static function from(mixed ...$payloads): static
    {
        return parent::from(['id' => 1, 'plate' => 'DEMO']);
    }
}
