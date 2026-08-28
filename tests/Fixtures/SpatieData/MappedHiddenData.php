<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;

/**
 * A class-level deny-list naming a property that publishes under another key. `#[Hidden]` matches the
 * PROPERTY, so this hides it and reports nothing — naming the wire key instead is the mistake. Only
 * ever reflected.
 */
#[DocuccinoHidden('access_token')]
final class MappedHiddenData extends Data
{
    public function __construct(
        public int $id,
        #[MapName('token', 'token')]
        public string $access_token,
    ) {}
}
