<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\JsonApiPaginate;

/**
 * The single entry point for the `spatie/laravel-json-api-paginate` integration (Phase 5c). The
 * service provider spreads {@see extensions()} into the default set only when the package's service
 * provider class exists (`class_exists` guard, Telescope pattern), so docuccino/laravel never
 * hard-requires the package. Recognises the `jsonPaginate()` terminal and documents its JSON:API
 * `page[number]`/`page[size]` query parameters.
 */
final class JsonApiPaginateIntegration
{
    public const SERVICE_PROVIDER = 'Spatie\\JsonApiPaginate\\JsonApiPaginateServiceProvider';

    /**
     * The class-presence probe is injectable so the gated-off branch is testable where the package
     * is in fact present.
     *
     * @param  (callable(string): bool)|null  $probe
     */
    public static function installed(?callable $probe = null): bool
    {
        $probe ??= static fn (string $class): bool => class_exists($class);

        return $probe(self::SERVICE_PROVIDER);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            JsonApiPaginateParametersExtension::class,
            JsonApiPaginateResponsesExtension::class,
            // Environment-digest seam (A4): the renamable page parameter names + pagination mode reshape
            // the documented parameters, so they feed the document-level fragment-cache digest.
            JsonApiPaginateConfigDigestContributor::class,
        ];
    }
}
