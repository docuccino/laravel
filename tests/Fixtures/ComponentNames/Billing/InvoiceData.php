<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Billing;

/**
 * One half of a short-name collision: two `InvoiceData` classes in different namespaces, both
 * reachable from documented routes. Neither carries `#[SchemaName]`, so both ask the registry for
 * `InvoiceData`.
 */
final readonly class InvoiceData
{
    public function __construct(
        public int $id,
        public int $amountInCents,
    ) {}
}
