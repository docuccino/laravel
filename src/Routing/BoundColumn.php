<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Routing;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\RouteBindingKeyResolver;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;

/**
 * The column a bound path parameter's value is matched on, or null when nothing static settles it. One
 * reader, because the parameter's published `key` and the links that fill the parameter must name the
 * same column.
 *
 * A binder the application registered is asked FIRST and ends the question: the framework runs it before
 * implicit binding and ignores both the route's column and the model's route key, so publishing either
 * would name a column the server never looks at. Otherwise the route's own column wins where it names one
 * (`{post:slug}`), and the bound model's route key answers the rest ({@see RouteBindingKeyResolver}).
 * A model resolving its bindings in code of its own answers nothing, and neither does a scoped child
 * whose parent finds its children its own way ({@see EloquentModelReflector::resolvesOwnBinding()}).
 *
 * @internal
 */
final class BoundColumn
{
    public static function of(RouteContext $context, string $name): ?string
    {
        $bound = $context->routeBindings[$name] ?? null;

        if ($bound === null || in_array($name, $context->customBoundParameters, true) || self::resolvedInCode($context, $name)) {
            return null;
        }

        return $context->routeBindingFields[$name] ?? $context->routeBindingKeyName($bound);
    }

    /** Whether the model this segment binds, or the parent it is scoped to, finds the record in code of its own. */
    public static function resolvedInCode(RouteContext $context, string $name): bool
    {
        $bound = $context->routeBindings[$name] ?? null;
        $parent = $context->scopedBindings[$name] ?? null;
        $parentBound = $parent === null ? null : ($context->routeBindings[$parent] ?? null);

        return ($bound !== null && EloquentModelReflector::resolvesOwnBinding($bound))
            || ($parentBound !== null && EloquentModelReflector::resolvesChildBindings($parentBound));
    }
}
