<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Billing;

use Docuccino\Attributes\SchemaName;

/**
 * The escape hatch pointed at its own foot: two classes both claiming `Statement`. An author-chosen
 * name is still just a claim on the component namespace, so this has to collide like any other.
 */
#[SchemaName('Statement')]
final readonly class StatementData
{
    public function __construct(
        public int $period,
    ) {}
}
