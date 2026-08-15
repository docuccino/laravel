<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Support;

/**
 * The other half of the short-name collision — a genuinely different shape, so the registry cannot
 * dedupe the two structurally.
 */
final readonly class InvoiceData
{
    public function __construct(
        public string $reference,
    ) {}
}
