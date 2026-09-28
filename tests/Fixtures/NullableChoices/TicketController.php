<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\NullableChoices;

/** Takes an {@see UpdateTicketRequest} and answers with a {@see TicketSummary}. */
final class TicketController
{
    public function update(UpdateTicketRequest $request): TicketSummary
    {
        return new TicketSummary;
    }
}
