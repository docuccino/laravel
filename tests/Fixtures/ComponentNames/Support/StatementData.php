<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Support;

use Docuccino\Attributes\SchemaName;

/**
 * The second claimant of `Statement` — a different class, a different shape, the same chosen name.
 */
#[SchemaName('Statement')]
final readonly class StatementData
{
    public function __construct(
        public string $summary,
    ) {}
}
