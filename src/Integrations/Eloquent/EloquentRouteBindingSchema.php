<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\RouteBindingFieldSchemaResolver;
use Docuccino\Core\Inference\ClassRef;

/**
 * The gated route-binding schema resolver contributed by the Eloquent integration: types a
 * route-model-bound path parameter from the bound model's route key (uuid/ulid/string/integer) via
 * {@see EloquentModelReflector::keySchemaFor()}, and a `{post:slug}` parameter from THAT column via
 * {@see EloquentModelReflector::columnSchemaFor()}. Contributed only when `eloquent` is enabled, so a
 * disabled integration leaves the path parameter to the built-in string fallback rather than typing
 * it off the model.
 */
final class EloquentRouteBindingSchema implements RouteBindingFieldSchemaResolver
{
    public function __construct(
        private readonly EloquentModelReflector $reflector = new EloquentModelReflector,
    ) {}

    /**
     * Always resolves a schema (the historical `integer` default for a non-model binding, else the
     * model's route-key schema) — never null — so an ENABLED Eloquent integration types every bound
     * path parameter exactly as before. A DISABLED integration contributes no resolver at all, which
     * is where the string fallback comes from.
     *
     * @return array<string, mixed>
     */
    public function keySchemaFor(string $modelFqcn): array
    {
        return EloquentModelReflector::keySchemaFor($modelFqcn);
    }

    /**
     * Unlike {@see keySchemaFor()} this one DOES answer null — for a non-Eloquent binding, or a column
     * nothing types — and the caller then documents a plain string. There is no `integer` default to
     * degrade to: the route key is a different column, so its schema would be a wrong answer rather
     * than a weak one.
     *
     * @return array<string, mixed>|null
     */
    public function fieldSchemaFor(RouteContext $context, string $modelFqcn, string $field): ?array
    {
        if (! EloquentModelReflector::isModel($modelFqcn)) {
            return null;
        }

        // The `@property` tags this reads live in the model file (and its parents'), so retyping a
        // column has to invalidate the warm fragment.
        $metadata = $context->engine->classMetadata(new ClassRef($modelFqcn));
        $context->recordDependencyFiles($metadata->dependencyFiles);

        return $this->reflector->columnSchemaFor($modelFqcn, $field, $metadata);
    }
}
