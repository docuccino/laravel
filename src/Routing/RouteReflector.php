<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Closure;
use Docuccino\Core\Inference\ActionRef;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

/**
 * Resolves a Laravel {@see Route}'s handler to a {@see ReflectedAction}. Handles the three
 * action shapes: a `Class@method` controller, a single-action invokable class (`__invoke`), and
 * a closure route. Returns null when the handler cannot be reflected (e.g. a string action whose
 * class is missing) so callers can fall back to a skeleton.
 *
 * @internal
 */
final class RouteReflector
{
    public function forRoute(Route $route): ?ReflectedAction
    {
        $uses = $route->getAction('uses');

        try {
            if ($uses instanceof Closure) {
                $reflection = new ReflectionFunction($uses);

                return new ReflectedAction(
                    actionRef: new ActionRef(
                        file: (string) $reflection->getFileName(),
                        class: null,
                        method: '{closure}',
                        line: (int) $reflection->getStartLine(),
                    ),
                    reflection: $reflection,
                    controllerClass: null,
                );
            }

            if (is_string($uses)) {
                [$class, $method] = $this->splitAction($uses);

                if ($class === null || ! class_exists($class)) {
                    return null;
                }

                if (! (new ReflectionClass($class))->hasMethod($method)) {
                    return null;
                }

                $reflection = new ReflectionMethod($class, $method);

                return new ReflectedAction(
                    actionRef: new ActionRef(
                        file: (string) $reflection->getFileName(),
                        class: $class,
                        method: $method,
                        line: (int) $reflection->getStartLine(),
                    ),
                    reflection: $reflection,
                    controllerClass: $class,
                );
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array{0: class-string|null, 1: string}
     */
    private function splitAction(string $uses): array
    {
        if (str_contains($uses, '@')) {
            [$class, $method] = explode('@', $uses, 2);

            return class_exists($class) ? [$class, LaravelActionRouteMethod::resolve($class, $method)] : [null, $method];
        }

        // A single-action controller referenced by class name resolves through __invoke — or, for a
        // laravel-actions action registered invokably, through its asController()/handle() method.
        return class_exists($uses) ? [$uses, LaravelActionRouteMethod::resolve($uses, '__invoke')] : [null, '__invoke'];
    }
}
