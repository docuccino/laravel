<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Support;

/**
 * The unattributed twin of {@see \Docuccino\Laravel\Tests\Fixtures\ComponentNames\Billing\LedgerData},
 * which keeps the plain short name once the other class has been renamed.
 */
final readonly class LedgerData
{
    public function __construct(
        public string $note,
    ) {}
}
