<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use ReflectionClass;

/**
 * A non-toggleable route-reflection probe: resolves which method an invokable route actually dispatches
 * on a `lorisleiva/laravel-actions` action. This is route IDENTITY resolution, not a documentation
 * contribution — gating it would make Docuccino reflect the trait's `__invoke(mixed ...$args)` forwarder
 * (garbage) instead of the real signature — so it lives in the routing layer and always runs, rather
 * than in the (per-document-toggleable) laravel-actions integration. It carries only the framework/
 * package trait knowledge it needs (mirroring the package's `ControllerDecorator::getDefaultRouteMethod()`),
 * so `Docuccino\Laravel\Routing` never reaches into `Docuccino\Laravel\Integrations`.
 *
 * All checks are guarded by the trait's presence, so this is inert when the package is absent.
 */
final class LaravelActionRouteMethod
{
    private const CONTROLLER_TRAIT = 'Lorisleiva\\Actions\\Concerns\\AsController';

    /**
     * Resolve the method a route dispatches on an action. Only an invokable registration (`__invoke`,
     * the trait's forwarder) is remapped — an explicit `[Action::class, 'method']` registration is
     * honoured verbatim — mirroring the package's own `replaceRouteMethod()`.
     */
    public static function resolve(string $fqcn, string $method): string
    {
        if ($method !== '__invoke' || ! self::isAction($fqcn) || ! class_exists($fqcn)) {
            return $method;
        }

        $reflection = new ReflectionClass($fqcn);

        if ($reflection->hasMethod('asController')) {
            return 'asController';
        }

        return $reflection->hasMethod('handle') ? 'handle' : $method;
    }

    /** Whether an FQCN is a laravel-actions action used as a controller (carries the AsController trait). */
    private static function isAction(string $fqcn): bool
    {
        if (! trait_exists(self::CONTROLLER_TRAIT) || ! class_exists($fqcn)) {
            return false;
        }

        $traits = [];
        foreach (array_merge([$fqcn], class_parents($fqcn) ?: []) as $class) {
            self::collectTraits($class, $traits);
        }

        return isset($traits[self::CONTROLLER_TRAIT]);
    }

    /**
     * @param  array<string, string>  $acc
     */
    private static function collectTraits(string $class, array &$acc): void
    {
        foreach (class_uses($class) ?: [] as $trait) {
            if (! isset($acc[$trait])) {
                $acc[$trait] = $trait;
                self::collectTraits($trait, $acc);
            }
        }
    }
}
