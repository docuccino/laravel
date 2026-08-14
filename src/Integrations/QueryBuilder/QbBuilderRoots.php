<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use Docuccino\Core\Inference\ActionRef;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use Throwable;

/**
 * The extra trace roots for an action that is HANDED its builder: a container-injected parameter typed to
 * a `Spatie\QueryBuilder\QueryBuilder` SUBCLASS configures its allow-lists in its own constructor, and no
 * call in the action body leads there — a `new` is not a call the trace descends into. Each such
 * parameter's constructor is a root of its own.
 *
 * Deliberately narrow: only a subclass of the package's builder, and only a constructor that subclass
 * declares (the vendor one is never a root). Roots come back in reflection parameter order — source order
 * — and deduped, so the walk stays deterministic and a repeated type is never traced twice.
 *
 * Narrow in one more way: this is the one place a root is CHOSEN from a type hint rather than handed over,
 * so a builder subclass an installed package ships is refused. Tracing it would put a vendor file in the
 * document's allow-lists and in the fragment cache's dependencies, against the engine's never-into-vendor
 * invariant — which the engine cannot enforce here, since a seeded root and an action root reach it alike.
 */
final class QbBuilderRoots
{
    private const QUERY_BUILDER = 'Spatie\\QueryBuilder\\QueryBuilder';

    /**
     * @param  (callable(string): bool)|null  $isVendorFile  the app's vendor boundary; without one nothing
     *                                                       is refused, which is what an in-process test
     *                                                       with no application around it wants
     * @return list<ActionRef>
     */
    public static function forAction(ActionRef $action, ?callable $isVendorFile = null): array
    {
        $roots = [];
        foreach (self::parameters($action) as $parameter) {
            $constructor = self::builderConstructor($parameter->getType());
            if ($constructor === null) {
                continue;
            }

            $file = $constructor->getFileName();
            if ($file === false || ($isVendorFile !== null && $isVendorFile($file))) {
                continue;
            }

            $line = $constructor->getStartLine();
            $root = new ActionRef($file, $constructor->getDeclaringClass()->getName(), '__construct', $line === false ? 0 : $line);
            $roots[$root->symbol()] = $root;
        }

        return array_values($roots);
    }

    /**
     * The action's parameters, or none at all when it can't be reflected — a closure route, or a class this
     * process can't load. Reflection is honest here: the adapter runs inside the application.
     *
     * Autoloading is inside the try on purpose: a class whose file exists but won't compile raises from
     * `class_exists()` itself, and this integration being irrelevant to a route must never cost that route
     * its documentation.
     *
     * @return list<ReflectionParameter>
     */
    private static function parameters(ActionRef $action): array
    {
        try {
            if ($action->class === null || ! class_exists($action->class)) {
                return [];
            }

            return (new ReflectionMethod($action->class, $action->method))->getParameters();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The constructor to trace for a parameter type, or null when the type isn't a builder subclass or the
     * only constructor it has is the package's own — which configures nothing this documents. Unloadable
     * for the same reason as {@see parameters()}, and just as harmless.
     */
    private static function builderConstructor(?ReflectionType $type): ?ReflectionMethod
    {
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        try {
            $fqcn = $type->getName();
            if (! class_exists($fqcn) || ! is_subclass_of($fqcn, self::QUERY_BUILDER)) {
                return null;
            }

            $constructor = (new ReflectionClass($fqcn))->getConstructor();
            if ($constructor === null) {
                return null;
            }

            return is_subclass_of($constructor->getDeclaringClass()->getName(), self::QUERY_BUILDER)
                ? $constructor
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
