<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Extensions\Contracts\RouteBindingSchemaResolver;

/**
 * The gated {@see RouteBindingSchemaResolver} contributed by the Eloquent integration: types a
 * route-model-bound path parameter from the bound model's route key (uuid/ulid/string/integer) via
 * {@see EloquentModelReflector::keySchemaFor()}. Contributed only when `eloquent` is enabled, so a
 * disabled integration leaves the path parameter to the built-in string fallback rather than typing
 * it off the model.
 */
final class EloquentRouteBindingSchema implements RouteBindingSchemaResolver
{
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
}
