<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TaggedRules;

/** How an order reaches its customer. `Digital` is named by no exclude rule in {@see StoreShipmentRequest}. */
enum DeliveryMethod: string
{
    case Courier = 'courier';
    case Locker = 'locker';
    case Pickup = 'pickup';
    case Digital = 'digital';
}
