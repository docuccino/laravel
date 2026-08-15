<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Working;

/**
 * An ordinary DTO that happens to share a short name with a class the analyser cannot expand.
 */
final readonly class Gizmo
{
    public function __construct(
        public int $id,
    ) {}
}
