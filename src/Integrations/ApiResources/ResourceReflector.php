<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * The one place that names Laravel's resource classes (by FQCN string — the integration is always-on
 * but must not hard-reference symbols that only exist on newer Laravel). Distinguishes a plain
 * `JsonResource`, an anonymous resource collection, and a first-party JSON:API resource (Laravel 12.45
 * and later), plus a helper to tell whether a return type ultimately involves JSON:API (for the query-param
 * extension).
 */
final class ResourceReflector
{
    public const JSON_RESOURCE = 'Illuminate\\Http\\Resources\\Json\\JsonResource';

    public const RESOURCE_COLLECTION = 'Illuminate\\Http\\Resources\\Json\\ResourceCollection';

    public const ANONYMOUS_COLLECTION = 'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection';

    public const MISSING_VALUE = 'Illuminate\\Http\\Resources\\MissingValue';

    public const JSON_API_RESOURCE = 'Illuminate\\Http\\Resources\\JsonApi\\JsonApiResource';

    public const JSON_API_COLLECTION = 'Illuminate\\Http\\Resources\\JsonApi\\AnonymousResourceCollection';

    /** Laravel 13's `#[Collects]`: the resource a named collection collects, ahead of `$collects`. */
    public const COLLECTS_ATTRIBUTE = 'Illuminate\\Http\\Resources\\Attributes\\Collects';

    /** Whether an FQCN is any `JsonResource` (the schema mapper's trigger — includes subclasses). */
    public static function isResource(string $fqcn): bool
    {
        return is_a($fqcn, self::JSON_RESOURCE, true);
    }

    /** Whether an FQCN is an anonymous resource collection (`Resource::collection(...)`). */
    public static function isAnonymousCollection(string $fqcn): bool
    {
        return is_a($fqcn, self::ANONYMOUS_COLLECTION, true) || is_a($fqcn, self::JSON_API_COLLECTION, true);
    }

    /** Whether an FQCN is a `ResourceCollection` subclass of the application's own, not an anonymous one. */
    public static function isNamedCollection(string $fqcn): bool
    {
        return is_a($fqcn, self::RESOURCE_COLLECTION, true) && ! self::isAnonymousCollection($fqcn);
    }

    /** Whether an FQCN is any resource collection, anonymous or one of the application's own. */
    public static function isCollection(string $fqcn): bool
    {
        return self::isAnonymousCollection($fqcn) || self::isNamedCollection($fqcn);
    }

    /** Whether a named `ResourceCollection` subclass keeps Laravel's `toArray`, a list of what it collects. */
    public static function inheritsCollectionBody(string $fqcn): bool
    {
        return self::isNamedCollection($fqcn) && self::declaringClass($fqcn, 'toArray') === self::RESOURCE_COLLECTION;
    }

    /** The class that declares `$method` as `$fqcn` inherits it, or null where neither can be reflected. */
    public static function declaringClass(string $fqcn, string $method): ?string
    {
        try {
            return (new ReflectionMethod($fqcn, $method))->getDeclaringClass()->getName();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The resource a collection collects, as Laravel's `collects()` resolves it: `#[Collects]` on the class
     * itself where the installed framework ships the attribute, else the `$collects` default, else
     * `FooCollection` → `Foo` or `FooResource`. Null when none names a resource.
     */
    public static function collects(string $fqcn): ?string
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        $class = new ReflectionClass($fqcn);
        $declared = self::collectsAttribute($class) ?? $class->getDefaultProperties()['collects'] ?? null;

        $candidates = is_string($declared) && $declared !== ''
            ? [$declared]
            : (str_ends_with($fqcn, 'Collection') ? [substr($fqcn, 0, -10), substr($fqcn, 0, -10).'Resource'] : []);

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return self::isResource($candidate) ? $candidate : null;
            }
        }

        return null;
    }

    /**
     * The class `#[Collects]` names on `$class` — not a parent's, which PHP does not inherit and Laravel
     * reads no further than the class. A framework without the attribute ignores one written anyway.
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function collectsAttribute(ReflectionClass $class): ?string
    {
        if (! class_exists(self::COLLECTS_ATTRIBUTE)) {
            return null;
        }

        $attribute = $class->getAttributes(self::COLLECTS_ATTRIBUTE)[0] ?? null;
        if ($attribute === null) {
            return null;
        }

        $arguments = $attribute->getArguments();
        $named = $arguments['class'] ?? $arguments[0] ?? null;

        return is_string($named) ? $named : null;
    }

    /** Whether an FQCN is a Laravel first-party JSON:API resource (guarded by `class_exists`). */
    public static function isJsonApiResource(string $fqcn): bool
    {
        return class_exists(self::JSON_API_RESOURCE) && is_a($fqcn, self::JSON_API_RESOURCE, true);
    }

    /**
     * Whether a return type ultimately produces a JSON:API document — the resource itself or a
     * collection whose item is a JSON:API resource — so the `include`/`fields` params apply.
     */
    public static function involvesJsonApi(DType $type): bool
    {
        if (! $type instanceof ClassT) {
            return false;
        }

        if (self::isJsonApiResource($type->fqcn)) {
            return true;
        }

        if (! self::isAnonymousCollection($type->fqcn)) {
            return false;
        }

        $item = $type->typeArgs[0] ?? null;

        return $item instanceof ClassT && self::isJsonApiResource($item->fqcn);
    }
}
