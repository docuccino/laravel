<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteFilters;

use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\RouteFilter;

/**
 * The worked example on the multiple-documents guide, as code that runs. Its `includes()` body is the
 * line the page shows, and RouteFilterTest holds the two together — an example nobody executes is one
 * that stops working without anybody finding out.
 */
final readonly class NamedRoutes implements RouteFilter
{
    public function includes(RouteDescriptor $route): bool
    {
        return $route->name !== null;
    }
}
