<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;

/**
 * A Data class mapped by an application's own {@see ReversedNameMapper} rather than one of spatie's,
 * so the keys it really accepts are behind arbitrary code and are documented unmapped. Only reflected.
 */
#[MapName(ReversedNameMapper::class)]
final class CustomMappedData extends Data
{
    public function __construct(
        public string $displayName,
    ) {}
}
