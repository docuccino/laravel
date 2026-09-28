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

        return MiddlewareResolution::runs($middleware, $class, $aliases);
    }
}
