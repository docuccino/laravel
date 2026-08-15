<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\InheritedShapes;

/**
 * Routes for the shapes whose facts are declared in a file the returned class does not name.
 */
final class InheritedController
{
    public function enveloped(): array
    {
        return [];
    }
}
