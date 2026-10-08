<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

/** A note pinned to a link — a plain class whose own keys are required alike on both sides. */
final readonly class Note
{
    public function __construct(public string $text, public LinkAttachment $link) {}
}
