<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\NullableChoices;

/** A response whose fields hold one fixed value or null, and one of two or null, as their docblocks say. */
final class TicketSummary
{
    /** @var 'escalated'|null */
    public $flag;

    /** @var 'open'|'closed'|null */
    public $state;

    public int $id = 1;
}
