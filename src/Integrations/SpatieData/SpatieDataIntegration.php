<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

/**
 * The single entry point for the `spatie/laravel-data` integration (the documented template for a
 * conditional integration): it lists the extensions the integration contributes — the {@see DataSchema}
 * type mapper (Data classes → hoisted component schemas, response wrapping), the {@see DataRequestExtension}
 * (Data action params → request body/query, incl. a static `rules()` override), and the
 * {@see DataPartialsExtension} (`include`/`exclude`/`only`/`except` query params for a Data return that
 * opts into them). The service provider spreads these into the default set only when
 * `Spatie\LaravelData\Data` exists (`class_exists` guard), so docuccino/laravel never hard-requires the
 * package.
 */
final class SpatieDataIntegration
{
    /**
     * Whether the host app has `spatie/laravel-data` installed. The class-presence probe is injectable
     * so the gated-off branch (package absent) is testable where the package is in fact present.
     *
     * @param  (callable(string): bool)|null  $probe
     */
    public static function installed(?callable $probe = null): bool
    {
        $probe ??= static fn (string $class): bool => class_exists($class);

        return $probe(DataClassReflector::DATA);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            DataSchema::class,
            DataRequestExtension::class,
            DataPartialsExtension::class,
            // The success-status override resolver (calculateResponseStatus → 201/202): a gated
            // ResponseStatusResolver, so a disabled integration never re-homes a bare Data return's status.
            DataResponseStatus::class,
            // Environment-digest seam (A4): the global wrap key / name-mapping strategy / date format
            // reshape every documented Data class, so they feed the document-level fragment-cache digest.
            SpatieDataDigestContributor::class,
        ];
    }
}
