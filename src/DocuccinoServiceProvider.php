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
 * The adapter's service provider (spatie/laravel-package-tools): config, commands, the late-bound
 * {@see ExtensionRegistry} singleton behind the `Docuccino` facade, and the pipeline services with
 * their filesystem-path contextual bindings.
 *
 * Integrations may not import vendor classes (arch rule), so the provider is the one place that
 * reads a third-party package's own config or static runtime state and injects it — that's why the
 * bindings below look chatty.
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

        // The resolver reflects each route while filtering and stashes it here for the context builder
        // to read back O(1). Scoped, so both share one index per build and it resets between builds.
        $this->app->scoped(ResolvedRouteIndex::class);

        // Same deal: the inferred-handler tier writes response-fold deferrals, the summary transformer
        // drains them once per build.
        $this->app->scoped(HandlerDeferralLog::class);

        // Vendor-package controller routes are excluded by default (route:list --except-vendor
        // semantics); this is the boundary.
        $this->app->when(LaravelRouteResolver::class)
            ->needs(VendorRoutePolicy::class)
            ->give(fn (): VendorRoutePolicy => new VendorRoutePolicy($this->app->basePath('vendor')));

        // Provenance `source.file` paths are relative to the app base path (design §4); the resolver
        // falls back to a composer-root walk for files outside it (the workbench).
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

        // null = Laravel's default cache store.
        $this->app->when(DocumentCache::class)
            ->needs('$store')
            ->give(function (): ?string {
                $store = config('docuccino.cache.store');

                return is_string($store) ? $store : null;
            });

        $this->app->when(DocumentGenerator::class)
            ->needs('$generatorVersion')
            ->give(self::VERSION);

        // Core does the assembling; the generator metadata names this adapter. A second adapter would
        // bind its own name here.
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

        // The data-leakage lint itself is framework-agnostic core; the adapter just maps
        // docuccino.lint.leakage.* onto its options.
        $this->app->bind(SensitiveFieldLint::class, static function (): SensitiveFieldLint {
            /** @var array<string, mixed> $leakage */
            $leakage = (array) config('docuccino.lint.leakage', []);
            $allow = is_array($leakage['allow'] ?? null) ? array_values(array_filter($leakage['allow'], 'is_string')) : [];

            $options = new SensitiveFieldLintOptions(
                enabled: ($leakage['enabled'] ?? true) !== false,
                allow: $allow,
            );

            // Extra token → label heuristics merge over the defaults; existing tokens keep their label.
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

        // json-api-paginate's parameter names and sizes are renamable in its own config; an absent bag
        // falls back to defaults plus an info diagnostic. Its response envelope (pagination mode) and
        // the cache's environment digest read the same bag, hence the three bindings.
        $this->app->bind(JsonApiPaginateParametersExtension::class, static function (): JsonApiPaginateParametersExtension {
            /** @var array<string, mixed> $config */
            $config = (array) config('json-api-paginate', []);

            return new JsonApiPaginateParametersExtension(JsonApiPaginateConfig::fromArray($config));
        });

        $this->app->bind(JsonApiPaginateResponsesExtension::class, static function (): JsonApiPaginateResponsesExtension {
            /** @var array<string, mixed> $config */
            $config = (array) config('json-api-paginate', []);

            return new JsonApiPaginateResponsesExtension(JsonApiPaginateConfig::fromArray($config));
        });

        $this->app->bind(JsonApiPaginateConfigDigestContributor::class, static function (): JsonApiPaginateConfigDigestContributor {
            /** @var array<string, mixed> $config */
            $config = (array) config('json-api-paginate', []);

            return new JsonApiPaginateConfigDigestContributor(JsonApiPaginateConfig::fromArray($config));
        });

        // Likewise query-builder: filter/sort/include/fields are renamable, and its digest contributor
        // keys the fragment cache on the same names + strict mode.
        $this->app->bind(QueryBuilderParametersExtension::class, static function (): QueryBuilderParametersExtension {
            /** @var array<string, mixed> $config */
            $config = (array) config('query-builder', []);

            return new QueryBuilderParametersExtension(QueryBuilderConfig::fromArray($config));
        });

        $this->app->bind(QueryBuilderConfigDigestContributor::class, static function (): QueryBuilderConfigDigestContributor {
            /** @var array<string, mixed> $config */
            $config = (array) config('query-builder', []);

            return new QueryBuilderConfigDigestContributor(QueryBuilderConfig::fromArray($config));
        });

        // Passport's oauth2 scheme (and the cache digest below) need facts that only live on the
        // vendor class: the scope catalogue and which grants are enabled.
        $this->app->bind(PassportSecurityExtension::class, function (Application $app): PassportSecurityExtension {
            /** @var Repository $config */
            $config = $app->make('config');

            return new PassportSecurityExtension($config, self::passportRuntime());
        });

        $this->app->bind(PassportDigestContributor::class, function (Application $app): PassportDigestContributor {
            /** @var Repository $config */
            $config = $app->make('config');

            return new PassportDigestContributor($config, self::passportRuntime());
        });

        // spatie-data's globals shape output too: the name-mapping strategy (a whole-class default
        // rename), the response wrap key and the date format. One configured reflector is shared by
        // every spatie-data extension through the container.
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

        // Resolved from the container so tests (and users) can swap in a stub or the NullTypeEngine;
        // otherwise built from config, degrading to null on boot failure.
        $this->app->bind(TypeEngine::class, static function (Application $app): TypeEngine {
            /** @var array<string, mixed> $config */
            $config = (array) config('docuccino.engine', []);

            return $app->make(TypeEngineFactory::class)->make($config);
        });
    }

    /** A non-empty string config value, or null when unset/blank. */
    private static function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Passport's scope catalogue and enabled grants — empty when Passport isn't installed, which is
     * why the `class_exists` guard has to come first.
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
     * Registers the runtime viewer routes for each document with a `viewer.route`: the Scalar HTML
     * page, its `.json` spec, and the bundled Scalar asset. Access control lives in
     * {@see DocsController} (a `viewer.gate` ability, else local env only).
     */
    public function packageBooted(): void
    {
        // Master off-switch: no runtime endpoints exist at all when it's off.
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
     * A document's `viewer.middleware`, defaulting to `['web', 'throttle:60,1']` so the spec endpoint
     * is session-scoped and rate limited out of the box. Beware: if the app's `web` group resolves a
     * domain or tenant, these domain-less routes will 404 — configure a narrower stack.
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
