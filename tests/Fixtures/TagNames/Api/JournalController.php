<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TagNames\Api;

/** One half of a deliberate merge: `tags.map` sends `Journal` and `Daybook` to one tag on purpose. */
final class JournalController
{
    public function index(): array
    {
        return [];
    }
}
