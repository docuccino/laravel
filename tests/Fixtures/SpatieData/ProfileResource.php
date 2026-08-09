<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Resource;

/**
 * A spatie `Resource` (output-only base, does NOT extend Data) — exercises that the integration
 * triggers on the `BaseData` interface, not only the `Data` base class.
 */
final class ProfileResource extends Resource
{
    public function __construct(
        public string $handle,
    ) {}
}
