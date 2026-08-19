<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Locality\Neighbour;

use Docuccino\Attributes\Webhook;

/**
 * A webhook that has nothing to do with the anchor beside it.
 */
#[Webhook('locality.neighbour')]
final readonly class Neighbour
{
    public function __construct(
        public string $reference,
    ) {}
}
