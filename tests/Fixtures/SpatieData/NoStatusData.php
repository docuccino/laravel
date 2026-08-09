<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Concerns\BaseData as BaseDataConcern;
use Spatie\LaravelData\Concerns\ContextableData;
use Spatie\LaravelData\Contracts\BaseData as BaseDataContract;

/**
 * A spatie Data class (it satisfies `Contracts\BaseData`, so `DataClassReflector::isData()` is true)
 * that composes only the base concerns and NOT `ResponsableData` — so `calculateResponseStatus()` is
 * ABSENT altogether rather than trait-provided. Exercises `DataResponseStatus`'s `hasMethod()` guard,
 * which is distinct from the trait-file check that filters an inherited default. Only ever reflected.
 */
final class NoStatusData implements BaseDataContract
{
    use BaseDataConcern;
    use ContextableData;

    public function __construct(
        public string $id,
    ) {}
}
