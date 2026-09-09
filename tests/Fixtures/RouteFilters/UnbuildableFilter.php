<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteFilters;

use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\RouteFilter;
use RuntimeException;

/** A filter whose constructor throws — the failure only trying can find. */
final class UnbuildableFilter implements RouteFilter
{
    public function __construct()
    {
        throw new RuntimeException('the tenant registry is not configured');
    }

    public function includes(RouteDescriptor $route): bool
    {
        return true;
    }
}
