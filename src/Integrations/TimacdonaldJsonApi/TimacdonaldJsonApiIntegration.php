<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\TimacdonaldJsonApi;

/**
 * Entry point for the `timacdonald/json-api` integration — the JSON:API resource package Laravel's
 * first-party resources (12.45 and later) were upstreamed from. The provider spreads {@see extensions()} in only when
 * the package's base resource class exists, so docuccino/laravel never hard-requires it. The schema mapper
 * and parameters extension share the JSON:API infrastructure with the first-party `ApiResources`
 * integration.
 */
final class TimacdonaldJsonApiIntegration
{
    /**
     * The probe is injectable so the gated-off branch stays testable where the package is present.
     *
     * @param  (callable(string): bool)|null  $probe
     */
    public static function installed(?callable $probe = null): bool
    {
        $probe ??= static fn (string $class): bool => class_exists($class);

        return $probe(TimacdonaldResourceReflector::JSON_API_RESOURCE);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            TimacdonaldJsonApiResourceSchema::class,
            TimacdonaldJsonApiParametersExtension::class,
            TimacdonaldMediaType::class,
            // A server implementation bound at boot adds `jsonapi` to every document, so it keys the cache.
            ServerImplementationDigestContributor::class,
        ];
    }
}
