<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/** A small nested Data item used by AccountData's #[DataCollectionOf] collection. */
final class TagData extends Data
{
    public function __construct(
        public string $label,
    ) {}
}
