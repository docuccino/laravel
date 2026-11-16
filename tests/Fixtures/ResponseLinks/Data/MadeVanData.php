<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Van;
use Spatie\LaravelData\Data;

/** A van with a creation method of its own, which `from()` calls in place of copying the columns. */
final class MadeVanData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $plate,
    ) {}

    public static function fromVan(Van $van): self
    {
        return new self($van->id, strrev($van->plate));
    }
}
