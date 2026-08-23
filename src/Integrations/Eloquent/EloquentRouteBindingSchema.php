<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\RouteBindingFieldSchemaResolver;
use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;
use Docuccino\Core\Inference\ClassRef;

/**
 * The gated route-binding resolvers contributed by the Eloquent integration: it answers both binding
 * questions, typing a path parameter from the bound model's route key (uuid/ulid/string/integer) and a
 * `{post:slug}` parameter from THAT column. Contributed only when `eloquent` is enabled, so a disabled
 * integration leaves the path parameter to the built-in string fallback rather than typing it off the
 * model.
 */
final class EloquentRouteBindingSchema implements RouteBindingFieldSchemaResolver, RouteBindingSchemaResolver
{
    public function __construct(
        private readonly EloquentModelReflector $reflector = new EloquentModelReflector,
    ) {}

    /**
     * The bound model's route-key schema, or null for a binding that is no Eloquent model at all —
     * a custom `UrlRoutable` keys on whatever its `resolveRouteBinding` says, so guessing its shape
     * here would be a confident wrong answer. The caller owns the string fallback.
     *
     * @return array<string, mixed>|null
     */
    public function keySchemaFor(string $modelFqcn): ?array
    {
        return EloquentModelReflector::isModel($modelFqcn)
            ? $this->reflector->keySchemaFor($modelFqcn)
            : null;
    }

    /**
     * Unlike {@see keySchemaFor()} this one DOES answer null — for a non-Eloquent binding, or a column
     * nothing types — and the caller then documents a plain string
     * ({@see RouteBindingFieldSchemaResolver}).
     *
     * @return array<string, mixed>|null
     */
    public function fieldSchemaFor(RouteContext $context, string $modelFqcn, string $field): ?array
    {
        if (! EloquentModelReflector::isModel($modelFqcn)) { // a pure predicate on a class name
            return null;
        }

        // The `@property` tags this reads live in the model file (and its parents'), so retyping a
        // column has to invalidate the warm fragment.
        $metadata = $context->engine->classMetadata(new ClassRef($modelFqcn));
        $context->recordDependencyFiles($metadata->dependencyFiles);

        return $this->reflector->columnSchemaFor($modelFqcn, $field, $metadata);
    }
}
