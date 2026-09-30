<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteFilters;

use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\RouteFilter;
use RuntimeException;

/** A filter that breaks on the first route it is asked about, so a build fails while it walks its routes. */
final readonly class BreakingFilter implements RouteFilter
{
    public function includes(RouteDescriptor $route): bool
    {
        throw new RuntimeException('A filter that broke.');
    }
}
