<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TagNames\Api;

use Docuccino\Attributes\Group;

/**
 * One half of a default-tag merge: two `ReportController`s in different namespaces both short to
 * `Report`, so both groups of operations land under one tag with nothing said about it.
 */
final class ReportController
{
    public function index(): array
    {
        return [];
    }

    #[Group('Public Reports')]
    public function grouped(): array
    {
        return [];
    }
}
