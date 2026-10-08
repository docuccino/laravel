<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedUnion;

/** A tag holding a label — a plain class whose own keys are required alike on both sides. */
final readonly class Tag
{
    public function __construct(public string $slug, public LabelData $label) {}
}
