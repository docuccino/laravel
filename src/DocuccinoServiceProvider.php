<?php

declare(strict_types=1);

namespace Docuccino\Laravel;

use Docuccino\Core\Content\ContentCompiler;
use Docuccino\Core\Extensions\BuiltIn\AttributeOverridesExtension;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Lint\SensitiveFieldLint;
use Docuccino\Core\Lint\SensitiveFieldLintOptions;
use Docuccino\Core\Pipeline\Assembler;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use Docuccino\Core\Provenance\SourcePathResolver;
use Docuccino\Laravel\Commands\CacheCommand;
use Docuccino\Laravel\Commands\ClearCommand;
use Docuccino\Laravel\Commands\DiffCommand;
use Docuccino\Laravel\Commands\ExportCommand;
use Docuccino\Laravel\Commands\ValidateCommand;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Engine\TypeEngineFactory;
use Docuccino\Laravel\Http\DocsController;
use Docuccino\Laravel\Integrations\InferredHandler\HandlerDeferralLog;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateConfig;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateConfigDigestContributor;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateParametersExtension;
use Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateResponsesExtension;
use Docuccino\Laravel\Integrations\Passport\PassportDigestContributor;
use Docuccino\Laravel\Integrations\Passport\PassportIntegration;
use Docuccino\Laravel\Integrations\Passport\PassportRuntime;
use Docuccino\Laravel\Integrations\Passport\PassportSecurityExtension;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderConfig;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderConfigDigestContributor;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParametersExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\WrapResolver;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Docuccino\Laravel\Routing\ResolvedRouteIndex;
use Docuccino\Laravel\Routing\VendorRoutePolicy;
use Docuccino\Laravel\Runtime\DocumentCache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The Docuccino Laravel adapter service provider (spatie/laravel-package-tools): registers the
 * config, the export command, the late-bound {@see ExtensionRegistry} singleton (backing the
 * `Docuccino` facade), and the pipeline services with their filesystem-path contextual bindings.
 */
final class DocuccinoServiceProvider extends PackageServiceProvider
{
    public const string VERSION = '0.1.0';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('docuccino')
            ->hasConfigFile()
            ->hasCommands([
                ExportCommand::class,
                ValidateCommand::class,
                DiffCommand::class,
                CacheCommand::class,
                ClearCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ExtensionRegistry::class);

        // The route resolver reflects each route while filtering and records it here; the context
        // builder reads it back O(1), so a route is reflected once per build. Scoped so both share
        // one index within a request/build and it resets between them.
        $this->app->scoped(ResolvedRouteIndex::class);

        // The inferred-handler tier records per-callback response-fold deferrals here; the summary
        // transformer drains them once at document build. Scoped so both share one log per build.
        $this->app->scoped(HandlerDeferralLog::class);

        // The route resolver excludes vendor-package controller routes by default (route:list
        // --except-vendor semantics); supply the app's vendor directory as the boundary.
        $this->app->when(LaravelRouteResolver::class)
            ->needs(VendorRoutePolicy::class)
            ->give(fn (): VendorRoutePolicy => new VendorRoutePolicy($this->app->basePath('vendor')));

        // Provenance `source.file` paths are relativised against the app base path (design §4);
        // the resolver falls back to a composer-root walk for files outside it (the workbench).
        $this->app->bind(
            SourcePathResolver::class,
            fn (Application $app): SourcePathResolver => new RootRelativeSourcePathResolver($app->basePath()),
        );

        $this->app->when(DocumentConfigFactory::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        $this->app->when(AttributeOverridesExtension::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        $this->app->when(TypeEngineFactory::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        $this->app->when(TypeEngineFactory::class)
            ->needs('$tmpDir')
            ->give(fn (): string => $this->app->storagePath('docuccino'));

        // The runtime document cache uses the configured Laravel cache store (null = default store).
        $this->app->when(DocumentCache::class)
            ->needs('$store')
            ->give(function (): ?string {
                $store = config('docuccino.cache.store');

                return is_string($store) ? $store : null;
            });

        $this->app->when(DocumentGenerator::class)
            ->needs('$generatorVersion')
            ->give(self::VERSION);

        // The pipeline engine lives in core; this adapter labels the emitted generator metadata as
        // itself (a future/second adapter binds its own name — byte-identical for this one).
        $this->app->when(Assembler::class)
            ->needs('$generatorName')
            ->give('docuccino/laravel');

        // The OperationFragment cache (design §10): filesystem, off by default.
        $this->app->bind(FragmentCache::class, function (Application $app): FragmentCache {
            /** @var array<string, mixed> $cache */
            $cache = (array) config('docuccino.cache', []);
            $path = is_string($cache['path'] ?? null) ? $cache['path'] : $app->storagePath('docuccino/fragments');

            return new FragmentCache(
                enabled: (bool) ($cache['enabled'] ?? false),
                path: str_starts_with($path, '/') ? $path : $app->basePath($path),
                toolVersion: self::VERSION,
                specVersion: '1.0.0',
                identityVersion: 'v1',
            );
        });

        $this->app->when(DocumentBuilder::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        $this->app->when(ContentCompiler::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        // The data-leakage lint is core + framework-agnostic; the adapter only maps its config
        // (docuccino.lint.leakage.{enabled,allow,patterns}) onto the core options and registers it.
        $this->app->bind(SensitiveFieldLint::class, static function (): SensitiveFieldLint {
            /** @var array<string, mixed> $leakage */
            $leakage = (array) config('docuccino.lint.leakage', []);
            $allow = is_array($leakage['allow'] ?? null) ? array_values(array_filter($leakage['allow'], 'is_string')) : [];

            $options = new SensitiveFieldLintOptions(
                enabled: ($leakage['enabled'] ?? true) !== false,
                allow: $allow,
            );

            // Extra token → label heuristics MERGE over the default table (existing tokens keep their label).
            $patterns = [];
            foreach (is_array($leakage['patterns'] ?? null) ? $leakage['patterns'] : [] as $token => $label) {
                if (is_string($token) && is_string($label)) {
                    $patterns[$token] = $label;
                }
            }
            if ($patterns !== []) {
                $options = $options->withPatterns($patterns);
            }

            return new SensitiveFieldLint($options);
        });

        // The json-api-paginate integration reads the package's own config for the (renamable)
        // parameter names + sizes; an absent bag falls back to defaults + an info diagnostic.
        $this->app->bind(JsonApiPaginateParametersExtension::class, static function (): JsonApiPaginateParametersExtension {
            /** @var array<string, mixed> $config */
            $config = (array) config('json-api-paginate', []);

            return new JsonApiPaginateParametersExtension(JsonApiPaginateConfig::fromArray($config));
        });

        // The json-api-paginate response envelope depends on the SAME config (its pagination mode
        // decides length/simple/cursor), so it is wired from the live bag alongside the params side.
        $this->app->bind(JsonApiPaginateResponsesExtension::class, static function (): JsonApiPaginateResponsesExtension {
            /** @var array<string, mixed> $config */
            $config = (array) config('json-api-paginate', []);

            return new JsonApiPaginateResponsesExtension(JsonApiPaginateConfig::fromArray($config));
        });

        // The environment-digest contributor (A4) keys the fragment cache on the SAME renamable
        // parameter names + mode, so it reads the live bag the same way the extensions above do.
        $this->app->bind(JsonApiPaginateConfigDigestContributor::class, static function (): JsonApiPaginateConfigDigestContributor {
            /** @var array<string, mixed> $config */
            $config = (array) config('json-api-paginate', []);

            return new JsonApiPaginateConfigDigestContributor(JsonApiPaginateConfig::fromArray($config));
        });

        // The query-builder integration reads the package's own config for the (renamable) request
        // parameter names (filter/sort/include/fields); an absent bag falls back to defaults + an
        // info diagnostic.
        $this->app->bind(QueryBuilderParametersExtension::class, static function (): QueryBuilderParametersExtension {
            /** @var array<string, mixed> $config */
            $config = (array) config('query-builder', []);

            return new QueryBuilderParametersExtension(QueryBuilderConfig::fromArray($config));
        });

        // The environment-digest contributor (A4) keys the fragment cache on the SAME renamable
        // parameter names + strict mode, so it reads the live bag the same way the extension above does.
        $this->app->bind(QueryBuilderConfigDigestContributor::class, static function (): QueryBuilderConfigDigestContributor {
            /** @var array<string, mixed> $config */
            $config = (array) config('query-builder', []);

            return new QueryBuilderConfigDigestContributor(QueryBuilderConfig::fromArray($config));
        });

        // Passport's oauth2 scheme needs runtime facts (the scope catalogue + which grants were
        // enabled) that live on the vendor class. The integration stays vendor-import-free (arch
        // rule), so the provider — allowed to touch Passport — reads them here and injects them.
        $this->app->bind(PassportSecurityExtension::class, function (Application $app): PassportSecurityExtension {
            /** @var Repository $config */
            $config = $app->make('config');

            return new PassportSecurityExtension($config, self::passportRuntime());
        });

        // The environment-digest contributor (A4) needs the same runtime facts (app.url + scopes +
        // grants), read here and injected so the integration stays vendor-import-free.
        $this->app->bind(PassportDigestContributor::class, function (Application $app): PassportDigestContributor {
            /** @var Repository $config */
            $config = $app->make('config');

            return new PassportDigestContributor($config, self::passportRuntime());
        });

        // The spatie-data integration reads the package's own global config — none of which the
        // integration may import (the vendor-import-free arch rule): the name-mapping strategy (a
        // whole-class default rename), the response wrap key, and the date format. The provider is the
        // one place allowed to touch it, so it reads the values here and injects them (mirroring the
        // Passport runtime facts). A single configured reflector is shared by every spatie-data
        // extension via the container.
        $this->app->bind(DataClassReflector::class, static function (): DataClassReflector {
            return new DataClassReflector(
                globalInputMapper: self::stringConfig('data.name_mapping_strategy.input'),
                globalOutputMapper: self::stringConfig('data.name_mapping_strategy.output'),
            );
        });

        $this->app->bind(DataSchema::class, function (Application $app): DataSchema {
            $format = config('data.date_format');

            return new DataSchema(
                reflector: $app->make(DataClassReflector::class),
                dateFormat: is_string($format) && $format !== '' ? $format : 'Y-m-d\TH:i:sP',
                wrap: new WrapResolver(self::stringConfig('data.wrap')),
            );
        });

        // The engine is resolved from the container so tests (and users) can swap in a stub or the
        // NullTypeEngine; production builds it from config, degrading to null on boot failure.
        $this->app->bind(TypeEngine::class, static function (Application $app): TypeEngine {
            /** @var array<string, mixed> $config */
            $config = (array) config('docuccino.engine', []);

            return $app->make(TypeEngineFactory::class)->make($config);
        });
    }

    /** A non-empty string config value (a mapper FQCN / wrap key), or null when unset/blank. */
    private static function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Read Passport's runtime facts for the oauth2 scheme (empty when Passport is not installed): the
     * scope catalogue from `Passport::tokensCan()` and whether the password / implicit grants were
     * enabled. Kept in the provider so the integration itself never imports the vendor class.
     */
    private static function passportRuntime(): PassportRuntime
    {
        if (! class_exists(PassportIntegration::PASSPORT)) {
            return new PassportRuntime;
        }

        $scopes = [];
        foreach (Passport::scopes() as $scope) {
            if ($scope instanceof Scope) {
                $scopes[$scope->id] = $scope->description;
            }
        }

        return new PassportRuntime(
            $scopes,
            Passport::$passwordGrantEnabled === true,
            Passport::$implicitGrantEnabled === true,
        );
    }

    /**
     * Register the runtime viewer routes for each document that configures a `viewer.route`: the
     * Scalar HTML page, its `.json` spec, and the locally bundled Scalar asset. Guarding lives in
     * the controller (a `viewer.gate` ability, else local env only).
     */
    public function packageBooted(): void
    {
        // The master off-switch (security M3): when disabled, register no runtime endpoints at all.
        if (config('docuccino.enabled', true) === false) {
            return;
        }

        /** @var array<string, mixed> $documents */
        $documents = (array) config('docuccino.documents', []);

        foreach ($documents as $key => $document) {
            if (! is_array($document)) {
                continue;
            }

            $viewer = is_array($document['viewer'] ?? null) ? $document['viewer'] : [];
            $route = $viewer['route'] ?? null;
            if (! is_string($route) || $route === '') {
                continue;
            }

            $base = '/'.ltrim($route, '/');
            $middleware = self::viewerMiddleware($viewer);

            Route::get($base, [DocsController::class, 'show'])->middleware($middleware)->defaults('document', (string) $key);
            Route::get($base.'.json', [DocsController::class, 'spec'])->middleware($middleware)->defaults('document', (string) $key);
            Route::get($base.'/assets/scalar.js', [DocsController::class, 'asset'])->middleware($middleware);
        }
    }

    /**
     * The middleware stack for a document's viewer routes (security M1/M2): `viewer.middleware`,
     * defaulting to `['web', 'throttle:60,1']` so the spec endpoint is session-scoped and rate
     * limited out of the box.
     *
     * @param  array<array-key, mixed>  $viewer
     * @return list<string>
     */
    private static function viewerMiddleware(array $viewer): array
    {
        $configured = $viewer['middleware'] ?? null;
        if (! is_array($configured)) {
            return ['web', 'throttle:60,1'];
        }

        return array_values(array_filter($configured, 'is_string'));
    }
}
