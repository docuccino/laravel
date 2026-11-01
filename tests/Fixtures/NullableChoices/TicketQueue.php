<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\NullableChoices;

/** The queue a ticket waits in: a backed enum, so its value set is a component of its own. */
enum TicketQueue: string
{
    case Support = 'support';
    case Billing = 'billing';
}
