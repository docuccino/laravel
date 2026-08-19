<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

/**
 * One first URI segment of an application's routes, with how many sit under it.
 *
 * @internal
 */
final readonly class RoutePrefix
{
    public function __construct(
        public string $prefix,
        public int $count,
    ) {}

    /** The `routes.include` pattern that would document this prefix. */
    public function pattern(): string
    {
        return $this->prefix === '/' ? '/' : $this->prefix.'/*';
    }
}
