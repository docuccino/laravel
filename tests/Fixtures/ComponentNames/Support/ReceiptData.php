<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Support;

/**
 * The twin of {@see \Docuccino\Laravel\Tests\Fixtures\ComponentNames\Billing\ReceiptData}: a different
 * class that happens to publish the same members today, and can stop doing so tomorrow.
 */
final readonly class ReceiptData
{
    public function __construct(
        public int $id,
    ) {}
}
