<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\BodylessAttribute;

/** The body {@see BodylessAttributeController} names — hoisted to a component wherever it is documented. */
final readonly class DiscardedBody
{
    public function __construct(
        public int $id,
    ) {}
}
