<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\JsonApiPaginate;

/**
 * Entry point for the `spatie/laravel-json-api-paginate` integration, which recognises the
 * `jsonPaginate()` terminal and documents its JSON:API `page[number]`/`page[size]` query parameters. The
 * provider spreads {@see extensions()} in only when the package's service provider exists, so
 * docuccino/laravel never hard-requires it.
 */
final class JsonApiPaginateIntegration
{
    public const SERVICE_PROVIDER = 'Spatie\\JsonApiPaginate\\JsonApiPaginateServiceProvider';

    /**
     * The probe is injectable so the gated-off branch stays testable where the package is present.
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
            JsonApiPaginateConfigDigestContributor::class,
        ];
    }
}
