<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Webhooks\Contested;

use Docuccino\Attributes\Webhook;

/**
 * One name, delivered as a POST when the shipment is first raised.
 */
#[Webhook('shipment.updated')]
final readonly class ShipmentCreated
{
    public function __construct(
        public string $reference,
    ) {}
}
