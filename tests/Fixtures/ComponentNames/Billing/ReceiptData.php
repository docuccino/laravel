<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Billing;

/**
 * One half of a short-name collision whose two shapes COINCIDE — the same members, so the two
 * registrations are byte-equal and structural dedupe would have collapsed them into one component.
 */
final readonly class ReceiptData
{
    public function __construct(
        public int $id,
    ) {}
}
