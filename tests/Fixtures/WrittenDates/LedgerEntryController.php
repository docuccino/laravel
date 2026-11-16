<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\WrittenDates;

/** Takes and sends {@see LedgerEntryData}, so both directions of each date are documented, and sends {@see Ledger}. */
final class LedgerEntryController
{
    public function ledger(): Ledger
    {
        return new Ledger;
    }

    public function store(LedgerEntryData $entry): LedgerEntryData
    {
        return $entry;
    }
}
