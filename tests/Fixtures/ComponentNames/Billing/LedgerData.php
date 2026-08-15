<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Billing;

use Docuccino\Attributes\SchemaName;

/**
 * The escape hatch in use: the twin that would otherwise be suffixed takes a distinct component name
 * from `#[SchemaName]`, so neither class collides.
 */
#[SchemaName('BillingLedger')]
final readonly class LedgerData
{
    public function __construct(
        public int $entries,
    ) {}
}
