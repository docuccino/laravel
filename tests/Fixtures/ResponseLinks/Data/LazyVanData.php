<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Attributes\AutoLazy;
use Spatie\LaravelData\Data;

/** A van whose class makes its properties lazy, so a property is sent only when something asks for it. */
#[AutoLazy]
final class LazyVanData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $plate,
    ) {}
}
