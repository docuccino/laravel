<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * The single entry point for the `spatie/laravel-query-builder` integration (design §Phase 4). The
 * service provider spreads {@see extensions()} into the default set only when the QueryBuilder class
 * exists (`class_exists` guard, Telescope pattern), so docuccino/laravel never hard-requires the
 * package. Productionises Spike B: recovers allowedFilters/Sorts/Includes/Fields + pagination
 * through any chain depth via the trace boundary.
 */
final class QueryBuilderIntegration
{
    public const QUERY_BUILDER = 'Spatie\\QueryBuilder\\QueryBuilder';

    /**
     * The class-presence probe is injectable so the gated-off branch is testable where the package
     * is in fact present.
     *
     * @param  (callable(string): bool)|null  $probe
     */
    public static function installed(?callable $probe = null): bool
    {
        $probe ??= static fn (string $class): bool => class_exists($class);

        return $probe(self::QUERY_BUILDER);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            QueryBuilderParametersExtension::class,
            // Environment-digest seam (A4): the renamable filter/sort/include/fields parameter names +
            // strict mode reshape every QB operation, so they feed the document-level fragment-cache digest.
            QueryBuilderConfigDigestContributor::class,
        ];
    }
}
