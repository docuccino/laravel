<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

/**
 * The entry point for the API Resources integration. The `JsonResource` mapper is always on
 * (illuminate/http ships with every Laravel app); the JSON:API pieces are added only when Laravel's
 * first-party `JsonApiResource` class exists (`class_exists` guard), so older Laravel versions are
 * unaffected.
 */
final class ApiResourcesIntegration
{
    /**
     * The always-on `JsonResource` mapper, plus the JSON:API pieces when Laravel's first-party
     * `JsonApiResource` class exists. The class-presence probe is injectable so the older-Laravel
     * branch (JSON:API absent) is testable where the class is in fact present.
     *
     * @param  (callable(string): bool)|null  $probe
     * @return list<class-string>
     */
    public static function extensions(?callable $probe = null): array
    {
        $probe ??= static fn (string $class): bool => class_exists($class);

        $extensions = [
            JsonResourceSchema::class,
            PaginatedResourceParametersExtension::class,
            PaginatedResourceResponsesExtension::class,
            CreatedResourceResponsesExtension::class,
            // The JSON:API media-type matcher (a gated PayloadMediaTypeResolver): only fires for a
            // first-party JSON:API resource, but registering it here means a disabled api_resources
            // integration contributes no matcher, so its resources stay application/json.
            ResourceMediaType::class,
        ];

        if ($probe(ResourceReflector::JSON_API_RESOURCE)) {
            $extensions[] = JsonApiResourceSchema::class;
            $extensions[] = JsonApiParametersExtension::class;
        }

        return $extensions;
    }
}
