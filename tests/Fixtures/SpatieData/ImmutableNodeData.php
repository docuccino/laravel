<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\Prohibited;
use Spatie\LaravelData\Data;

/**
 * `#[Prohibited]` on both kinds of property at once: a scalar the API refuses, and a NESTED Data object
 * it refuses — the one that has a subtree of its own to take with it. Only ever reflected.
 */
final class ImmutableNodeData extends Data
{
    public function __construct(
        public readonly string $name,
        #[Prohibited]
        public readonly string $slug,
        #[Prohibited]
        public readonly AddressData $registered_address,
    ) {}
}
