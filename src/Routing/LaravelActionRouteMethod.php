<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

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
    private const CONTROLLER_TRAIT = 'Lorisleiva\\Actions\\Concerns\\AsController';

    /**
     * Only an invokable registration is remapped; an explicit `[Action::class, 'method']` is honoured
     * verbatim, as in the package's own `replaceRouteMethod()`.
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

    /** An action used as a controller, i.e. one carrying the AsController trait. */
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
