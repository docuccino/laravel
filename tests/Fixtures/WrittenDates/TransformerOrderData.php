<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\WrittenDates;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\WithCastAndTransformer;
use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Transformers\DateTimeInterfaceTransformer;

/**
 * A date carrying two transformers, the application's declared first: laravel-data from 4.14 writes with
 * the `#[WithTransformer]`, and before that with whichever is declared first.
 */
final class TransformerOrderData extends Data
{
    public function __construct(
        #[WithCastAndTransformer(EpochTransformer::class), WithTransformer(DateTimeInterfaceTransformer::class, format: 'Y-m-d')]
        public CarbonImmutable $settledOn,
    ) {}
}
