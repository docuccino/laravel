<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteFilters;

/**
 * The collaborator {@see AllowListFilter} takes in its constructor, so resolving the filter out of the
 * container is what makes it work at all.
 */
final readonly class DocumentedPaths
{
    /**
     * @param  list<string>  $uris
     */
    public function __construct(private array $uris = ['/api/forms']) {}

    public function covers(string $uri): bool
    {
        return in_array($uri, $this->uris, true);
    }
}
