<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Transformers\DateTimeInterfaceTransformer;

/**
 * A van whose every property is something other than a plain copy of its column, each for one reason:
 * read through a cast, written through a transformer, computed, hidden, lazy, nullable, or typed for
 * something other than a key.
 */
final class PaintedVanData extends Data
{
    #[Computed]
    public string $vin;

    public function __construct(
        #[WithCast(DateTimeInterfaceCast::class)]
        public readonly string $plate,
        #[WithTransformer(DateTimeInterfaceTransformer::class)]
        public readonly int $id,
        #[Hidden]
        public readonly string $model_name,
        public readonly Lazy|string $make,
        public readonly ?int $mileage,
        public readonly float $weight,
    ) {}
}
