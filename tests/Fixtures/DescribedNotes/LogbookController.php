<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DescribedNotes;

/** Sends {@see Logbook} and {@see LogbookData} as response bodies. */
final class LogbookController
{
    public function show(): Logbook
    {
        return new Logbook;
    }

    public function summary(): LogbookData
    {
        return LogbookData::from(new Logbook);
    }
}
