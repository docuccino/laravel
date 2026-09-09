<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteFilters;

/** Buildable, invokable, and not a RouteFilter — the shape a convention-based contract would accept. */
final class NotAFilter
{
    public function __invoke(): bool
    {
        return true;
    }
}
