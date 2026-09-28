<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

/**
 * The entry point for the Eloquent model schema integration. Always on — illuminate/database ships
 * with every Laravel app — contributing the {@see ModelSchema} type mapper. A union of models, a
 * `MorphTo` included, is core's union of their components; the morph map reaches the document only
 * through the parent's type column ({@see MorphTypeValues}).
 */
final class EloquentIntegration
{
    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            ModelSchema::class,
            // The route-binding schema resolvers, both gated: a disabled Eloquent integration leaves
            // bound path params to the string fallback rather than typing them off the model.
            EloquentRouteBindingSchema::class,
            // A morphTo's type column publishes values read out of the morph map, so the map keys the cache.
            MorphMapDigestContributor::class,
        ];
    }
}
