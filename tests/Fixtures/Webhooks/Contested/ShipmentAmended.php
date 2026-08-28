<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Contested;

use Docuccino\Attributes\Webhook;

/**
 * The same name under a second method, which is the whole point of the fixture: the two bodies are
 * different contracts, so nothing may pick between them on a caller's behalf.
 */
#[Webhook('shipment.updated', method: 'put')]
final readonly class ShipmentAmended
{
    public function __construct(
        public string $reference,
        public int $revision,
    ) {}
}
