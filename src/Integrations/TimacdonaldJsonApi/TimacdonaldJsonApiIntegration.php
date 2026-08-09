<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\TimacdonaldJsonApi;

/**
 * The single entry point for the `timacdonald/json-api` integration (Phase 5c) — the pre-13 JSON:API
 * resource package Laravel 13's first-party resources were upstreamed from. The service provider
 * spreads {@see extensions()} into the default set only when the package's base resource class exists
 * (`class_exists` guard, Telescope pattern), so docuccino/laravel never hard-requires it. Both the
 * schema mapper and the parameters extension feed the JSON:API infrastructure shared with the
 * first-party `ApiResources` integration.
 */
final class TimacdonaldJsonApiIntegration
{
    /**
     * The class-presence probe is injectable so the gated-off branch is testable where the package
     * is in fact present.
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
            // The JSON:API media-type matcher (a gated PayloadMediaTypeResolver): a disabled integration
            // contributes no matcher, so its resources stay application/json.
            TimacdonaldMediaType::class,
        ];
    }
}
