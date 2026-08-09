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
 * The per-document integration enable/disable gate: an integration contributes its extensions only
 * when installed AND enabled for the document. `installed()` is the package-presence probe;
 * `integrations.<key>.enabled` is the orthogonal per-document question, read at extension-resolution
 * time — never at boot, never by mutating a probe.
 *
 * Everything installed defaults ON except `permission`, which is opt-in: documenting permission names
 * would leak the app's authorization taxonomy into a public spec. (Passport stays on — OAuth scopes
 * *are* the public contract.) That's the general rule for sensitive-by-activation integrations.
 *
 * The single lookup table, dataset-tested over every entry. {@see DefaultExtensions} consumes it by
 * key to preserve built-in ordering; the pipeline reads {@see diagnostics()}.
 *
 * @internal
 */
final class IntegrationToggles
{
    /**
     * The toggle table, keyed by config-bag name in built-in order. Laravel's own built-ins
     * (api_resources, eloquent, rate_limit) have no package to detect so their probe answers true;
     * package-backed ones delegate to their own `installed()`.
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
     * What the integration keyed by $key contributes to this document: its extensions when installed
     * and enabled, otherwise nothing.
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
     * One info diagnostic per installed-but-disabled integration (design §4): a default-off one points
     * at the switch, an explicit `enabled => false` just confirms the omission. Nothing fires when the
     * package is absent, so nobody gets nagged about an integration they don't use.
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
        // Default-off and never touched → the opt-in discovery message.
        if ($descriptor->optInHint !== null
            && ! $descriptor->defaultEnabled
            && ! $document->integrationEnabledExplicit($descriptor->key)) {
            return sprintf(
                '%s detected; the %s integration is opt-in — set integrations.%s.enabled = true to %s',
                $descriptor->package, $descriptor->key, $descriptor->key, $descriptor->optInHint,
            );
        }

        // Otherwise an explicit opt-out.
        return sprintf(
            '%s detected; the %s integration is disabled (integrations.%s.enabled = false) — its contributions are omitted from this document',
            $descriptor->package, $descriptor->key, $descriptor->key,
        );
    }
}
