<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Registry;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Integrations\ApiResources\ApiResourcesIntegration;
use Docuccino\Laravel\Integrations\Eloquent\EloquentIntegration;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateIntegration;
use Docuccino\Laravel\Integrations\LaravelActions\LaravelActionsIntegration;
use Docuccino\Laravel\Integrations\Passport\PassportIntegration;
use Docuccino\Laravel\Integrations\Permission\PermissionIntegration;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderIntegration;
use Docuccino\Laravel\Integrations\RateLimit\RateLimitIntegration;
use Docuccino\Laravel\Integrations\Sanctum\SanctumIntegration;
use Docuccino\Laravel\Integrations\SpatieData\SpatieDataIntegration;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldJsonApiIntegration;

/**
 * The per-document integration enable/disable gate.
 *
 * `installed()` answers "is the package present" — the unchanged, boot-time-agnostic probe. This
 * table adds the orthogonal, per-document question "does THIS document want it", read from the
 * `integrations.<key>.enabled` switch at extension-resolution time (already per-document) — never at
 * boot, never by mutating a probe. An integration contributes its extensions only when installed AND
 * enabled-for-this-document.
 *
 * Every integration defaults ON when its package is installed, EXCEPT `permission`
 * (spatie/laravel-permission), which defaults OFF: documenting permission names leaks the app's
 * internal authorization taxonomy into the public spec, so it is explicit opt-in. (Passport stays on —
 * OAuth scopes ARE the public contract; only permission flips.) Permission is the first member of the
 * "sensitive-by-activation integrations default off" principle.
 *
 * This is the single lookup table — dataset-tested over every entry. {@see DefaultExtensions} consumes
 * it by key to keep the built-in ordering; the pipeline reads {@see diagnostics()} to surface one
 * discoverability diagnostic per document per installed-but-disabled integration.
 *
 * @internal
 */
final class IntegrationToggles
{
    /**
     * The toggle table, keyed by config-bag name in built-in order. Framework built-ins that ship
     * with Laravel (api_resources, eloquent, rate_limit) have no package to detect, so their probe
     * answers true; the package-backed integrations delegate to their own `installed()` probe.
     *
     * @return array<string, IntegrationDescriptor>
     */
    public static function descriptors(): array
    {
        $descriptors = [
            new IntegrationDescriptor(
                'api_resources', 'Laravel API resources', true, null,
                static fn (?callable $probe): bool => true,
                static fn (): array => ApiResourcesIntegration::extensions(),
            ),
            new IntegrationDescriptor(
                'timacdonald_json_api', 'timacdonald/json-api', true, null,
                static fn (?callable $probe): bool => TimacdonaldJsonApiIntegration::installed($probe),
                static fn (): array => TimacdonaldJsonApiIntegration::extensions(),
            ),
            new IntegrationDescriptor(
                'eloquent', 'Eloquent', true, null,
                static fn (?callable $probe): bool => true,
                static fn (): array => EloquentIntegration::extensions(),
            ),
            new IntegrationDescriptor(
                'rate_limit', 'Laravel rate limiting', true, null,
                static fn (?callable $probe): bool => true,
                static fn (): array => RateLimitIntegration::extensions(),
            ),
            new IntegrationDescriptor(
                'spatie_data', 'spatie/laravel-data', true, null,
                static fn (?callable $probe): bool => SpatieDataIntegration::installed($probe),
                static fn (): array => SpatieDataIntegration::extensions(),
            ),
            new IntegrationDescriptor(
                'query_builder', 'spatie/laravel-query-builder', true, null,
                static fn (?callable $probe): bool => QueryBuilderIntegration::installed($probe),
                static fn (): array => QueryBuilderIntegration::extensions(),
            ),
            new IntegrationDescriptor(
                'json_api_paginate', 'spatie/laravel-json-api-paginate', true, null,
                static fn (?callable $probe): bool => JsonApiPaginateIntegration::installed($probe),
                static fn (): array => JsonApiPaginateIntegration::extensions(),
            ),
            new IntegrationDescriptor(
                'laravel_actions', 'lorisleiva/laravel-actions', true, null,
                static fn (?callable $probe): bool => LaravelActionsIntegration::installed($probe),
                static fn (): array => LaravelActionsIntegration::extensions(),
            ),
            new IntegrationDescriptor(
                'sanctum', 'laravel/sanctum', true, null,
                static fn (?callable $probe): bool => SanctumIntegration::installed($probe),
                static fn (): array => SanctumIntegration::extensions(),
            ),
            new IntegrationDescriptor(
                'passport', 'laravel/passport', true, null,
                static fn (?callable $probe): bool => PassportIntegration::installed($probe),
                static fn (): array => PassportIntegration::extensions(),
            ),
            new IntegrationDescriptor(
                'permission', 'spatie/laravel-permission', false, 'document permission requirements',
                static fn (?callable $probe): bool => PermissionIntegration::installed($probe),
                static fn (): array => PermissionIntegration::extensions(),
            ),
        ];

        $keyed = [];
        foreach ($descriptors as $descriptor) {
            $keyed[$descriptor->key] = $descriptor;
        }

        return $keyed;
    }

    /**
     * The extensions the integration keyed by $key contributes to this document — its own set when
     * installed AND enabled-for-this-document, otherwise none. The single gate {@see DefaultExtensions}
     * calls per integration (keeping the built-in ordering).
     *
     * @param  (callable(string): bool)|null  $probe
     * @return list<class-string|object>
     */
    public static function contribute(DocumentConfig $document, string $key, ?callable $probe = null): array
    {
        $descriptor = self::descriptors()[$key];

        return $descriptor->installed($probe) && $descriptor->enabledFor($document)
            ? $descriptor->extensions()
            : [];
    }

    /**
     * One info diagnostic per document per integration that is installed but disabled — the
     * discoverability signal (design §4). A default-off integration left untouched (permission,
     * awaiting opt-in) points the user at the switch; a deliberate opt-out (`enabled => false`)
     * simply confirms its contributions are omitted. Nothing fires when the package is absent, so an
     * app without an integration's package is never nagged about it. Deterministic ordering is the
     * collector's (never time-based).
     *
     * @param  (callable(string): bool)|null  $probe
     * @return list<Diagnostic>
     */
    public static function diagnostics(DocumentConfig $document, ?callable $probe = null): array
    {
        $diagnostics = [];
        foreach (self::descriptors() as $descriptor) {
            if (! $descriptor->installed($probe) || $descriptor->enabledFor($document)) {
                continue;
            }

            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'integration.disabled',
                message: self::message($document, $descriptor),
            );
        }

        return $diagnostics;
    }

    private static function message(DocumentConfig $document, IntegrationDescriptor $descriptor): string
    {
        // Default-off and never touched → the opt-in discovery message (permission's case).
        if ($descriptor->optInHint !== null
            && ! $descriptor->defaultEnabled
            && ! $document->integrationEnabledExplicit($descriptor->key)) {
            return sprintf(
                '%s detected; the %s integration is opt-in — set integrations.%s.enabled = true to %s',
                $descriptor->package, $descriptor->key, $descriptor->key, $descriptor->optInHint,
            );
        }

        // Otherwise a deliberate opt-out (`enabled => false`).
        return sprintf(
            '%s detected; the %s integration is disabled (integrations.%s.enabled = false) — its contributions are omitted from this document',
            $descriptor->package, $descriptor->key, $descriptor->key,
        );
    }
}
