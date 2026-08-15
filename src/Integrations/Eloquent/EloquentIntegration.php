<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

/**
 * The entry point for the Eloquent model schema integration. Always on — illuminate/database ships
 * with every Laravel app — contributing the {@see ModelSchema} type mapper and {@see MorphToSchema}
 * (polymorphic morph unions → discriminated `oneOf`).
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
            MorphToSchema::class,
            // The route-binding schema resolvers, both gated: a disabled Eloquent integration leaves
            // bound path params to the string fallback rather than typing them off the model.
            EloquentRouteBindingSchema::class,
            // Environment-digest seam (A4): the polymorphic morph map drives MorphTo discriminators,
            // so a morph-map change must invalidate the document-level fragment-cache digest.
            MorphMapDigestContributor::class,
        ];
    }
}
