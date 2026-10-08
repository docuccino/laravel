<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

use Spatie\LaravelData\Data;

/** A link pinned under a label — a Data class, hoisted by its own mapper, holding a plain one. */
final class PinnedLinkData extends Data
{
    public function __construct(public string $label, public LinkAttachment $link) {}
}
