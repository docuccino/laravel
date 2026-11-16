<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Data;

/** A van with no constructor: `from()` sets each property directly, and there is nothing to hand arguments to. */
final class BareVanData extends Data
{
    public int $id;

    public string $plate;
}
