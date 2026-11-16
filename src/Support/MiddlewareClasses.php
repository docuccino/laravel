<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Illuminate\Routing\Router;

/**
 * Which middleware CLASS a route's entry runs, for the readers that ask by class rather than by
 * spelling: the entry resolved through this build's alias map ({@see MiddlewareAliases},
 * {@see MiddlewareResolution::runs()}), re-read whenever the router's own map has moved. The resolver
 * keys each fragment on the aliases its middleware names, and the class a reader resolved keys it
 * through its declaration files.
 */
final class MiddlewareClasses
{
    /** @var array<string, string>|null */
    private ?array $aliases = null;

    /** @var array<array-key, mixed> */
    private array $read = [];

    public function __construct(private readonly Router $router) {}

    /** @param  class-string  $class */
    public function runs(RouteContext $context, string $middleware, string $class): bool
    {
        return MiddlewareResolution::runs($middleware, $class, $this->aliasMap($context, $middleware));
    }

    /**
     * The entry as the framework resolves it: its alias's class with the arguments kept, or the entry
     * itself where no alias answers. What a reader of an entry's spelling reads it from where the
     * application named the middleware under an alias of its own.
     */
    public function resolve(RouteContext $context, string $middleware): string
    {
        return MiddlewareResolution::resolve($middleware, $this->aliasMap($context, $middleware));
    }

    /**
     * This build's alias map, the class the entry resolves to recorded on the route.
     *
     * @return array<string, string>
     */
    private function aliasMap(RouteContext $context, string $middleware): array
    {
        $registered = $this->router->getMiddleware();
        if ($this->aliases === null || $registered !== $this->read) {
            $this->read = $registered;
            $this->aliases = MiddlewareAliases::of($this->router);
        }
        $aliases = $this->aliases;

        // The route's own spelling is keyed already; an alias's class is what the answer was read from.
        $resolved = MiddlewareResolution::className($middleware, $aliases);
        if ($resolved !== MiddlewareName::name($middleware)) {
            $context->recordDependencyFiles(DeclarationFiles::of($resolved));
        }

        return $aliases;
    }
}
