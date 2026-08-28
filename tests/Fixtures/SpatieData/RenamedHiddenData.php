<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;

/**
 * A Data class whose class-level deny-list went stale: the property is `access_token`, the attribute
 * still says `accessToken`. It also maps a property to another wire key, so the report is pinned to
 * judging the PROPERTY's name rather than the key it publishes under. Only ever reflected.
 */
#[DocuccinoHidden('accessToken')]
final class RenamedHiddenData extends Data
{
    public function __construct(
        public int $id,
        #[MapName('label', 'label')]
        public string $name,
        public string $access_token,
    ) {}
}
