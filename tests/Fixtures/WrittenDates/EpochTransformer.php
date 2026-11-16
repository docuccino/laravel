<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\WrittenDates;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Spatie\LaravelData\Casts\Cast;
use Spatie\LaravelData\Support\Creation\CreationContext;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Transformers\Transformer;

/** An application's own date cast and transformer: reads and writes a date as an integer epoch. */
final class EpochTransformer implements Cast, Transformer
{
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp(is_numeric($value) ? (int) $value : 0);
    }

    public function transform(DataProperty $property, mixed $value, TransformationContext $context): int
    {
        return $value instanceof DateTimeInterface ? $value->getTimestamp() : 0;
    }
}
