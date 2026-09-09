<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteFilters;

use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\RouteFilter;

/** A filter that answers from an injected collaborator rather than from anything it could hard-code. */
final readonly class AllowListFilter implements RouteFilter
{
    public function __construct(private DocumentedPaths $paths) {}

    public function includes(RouteDescriptor $route): bool
    {
        return $this->paths->covers($route->uri);
    }
}
