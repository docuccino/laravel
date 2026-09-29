<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Laravel\Support\LaravelActionHooks;
use ReflectionClass;

/**
 * Resolves which method an invokable route actually dispatches on a `lorisleiva/laravel-actions` action,
 * mirroring the package's `ControllerDecorator::getDefaultRouteMethod()`.
 *
 * This is route identity, not a documentation contribution, so it lives here and always runs instead of
 * inside the toggleable laravel-actions integration — gate it and we'd reflect the trait's
 * `__invoke(mixed ...$args)` forwarder instead of the real signature. Every check is guarded by the
 * trait's presence, so it's inert without the package.
 */
final class LaravelActionRouteMethod
{
    /**
     * Only an invokable registration is remapped; an explicit `[Action::class, 'method']` is honoured
     * verbatim, as in the package's own `replaceRouteMethod()`.
     */
    public static function resolve(string $fqcn, string $method): string
    {
        if ($method !== '__invoke' || ! class_exists($fqcn) || ! LaravelActionHooks::isAction($fqcn)) {
            return $method;
        }

        $reflection = new ReflectionClass($fqcn);

        if ($reflection->hasMethod('asController')) {
            return 'asController';
        }

        return $reflection->hasMethod('handle') ? 'handle' : $method;
    }
}
