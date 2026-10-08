<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

use Spatie\LaravelData\Data;

/** A label whose colour a client may leave out — a Data class, which publishes one shape to both sides. */
final class LabelData extends Data
{
    public function __construct(public string $text, public string $colour = 'grey') {}
}
