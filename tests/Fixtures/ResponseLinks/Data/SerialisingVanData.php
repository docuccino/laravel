<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Data;

/** A van that writes its own serialised form, so what it sends under `id` is its own business. */
final class SerialisingVanData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $plate,
    ) {}

    public function toArray(): array
    {
        return ['id' => $this->plate, 'plate' => $this->id];
    }
}
