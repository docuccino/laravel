<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\NullableChoices;

/**
 * A response whose fields hold one fixed value or null, and one of two or null, as their docblocks say.
 * `queue` and `replies` carry an `@example` beside a nullable type, which publishes with no `type` of its
 * own wherever the null is an `anyOf` branch — always for an enum, and for a scalar under the `anyof`
 * policy.
 */
final class TicketSummary
{
    /** @var 'escalated'|null */
    public $flag;

    /** @var 'open'|'closed'|null */
    public $state;

    public int $id = 1;

    /**
     * The queue the ticket waits in, until it is triaged.
     *
     * @example null
     */
    public ?TicketQueue $queue = null;

    /**
     * How many replies the customer has sent, where they have.
     *
     * @example 3
     */
    public ?int $replies = null;
}
