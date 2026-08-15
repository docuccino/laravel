<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames;

/**
 * Two routes returning two namespaces' worth of same-short-name DTOs, so the component-name
 * collisions arise ACROSS routes — the way they do in a real app — rather than inside one shape.
 */
final class CollisionController
{
    public function billing(): array
    {
        return [];
    }

    public function support(): array
    {
        return [];
    }
}
