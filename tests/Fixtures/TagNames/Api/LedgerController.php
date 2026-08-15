<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TagNames\Api;

/** The negative path: a controller nothing else shares a short name with. It must raise nothing. */
final class LedgerController
{
    public function index(): array
    {
        return [];
    }
}
