<?php

declare(strict_types=1);

namespace Docuccino\Laravel;

use Composer\InstalledVersions;
use Docuccino\Core\Content\ContentCompiler;
use Docuccino\Core\Examples\ExampleRedaction;
use Docuccino\Core\Examples\RecordedExampleAudit;
use Docuccino\Core\Extensions\BuiltIn\AttributeExamplesExtension;
use Docuccino\Core\Extensions\BuiltIn\AttributeOverridesExtension;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Lint\ExampleSchemaLint;
use Docuccino\Core\Lint\LintRuleOptions;
use Docuccino\Core\Lint\MissingDescriptionLint;
use Docuccino\Core\Lint\OperationIdStyleLint;
use Docuccino\Core\Lint\SensitiveFieldLint;
use Docuccino\Core\Lint\UndocumentedTagLint;
use Docuccino\Core\Lint\UnpinnedRedirectLint;
use Docuccino\Core\Lint\VacuousUnionLint;
use Docuccino\Core\Pipeline\Assembler;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use Docuccino\Core\Provenance\SourcePathResolver;
use Docuccino\Laravel\Commands\CacheCommand;
use Docuccino\Laravel\Commands\ClearCommand;
use Docuccino\Laravel\Commands\CoverageCommand;
use Docuccino\Laravel\Commands\DiffCommand;
use Docuccino\Laravel\Commands\ExplainCommand;
use Docuccino\Laravel\Commands\ExportCommand;
use Docuccino\Laravel\Commands\InstallCommand;
use Docuccino\Laravel\Commands\MemoryLimitOption;
use Docuccino\Laravel\Commands\ValidateCommand;
use Docuccino\Laravel\Commands\VersionChangesCommand;
use Docuccino\Laravel\Commands\WatchCommand;
use Docuccino\Laravel\Config\ConfigPublisher;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Config\LeakageOptions;
use Docuccino\Laravel\Engine\ConsoleBuild;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Engine\TypeEngineFactory;
use Docuccino\Laravel\Extensions\RecordedExamplesExtension;
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
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\SpatieData\WrapResolver;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;
use Docuccino\Laravel\Pipeline\BuildFingerprint;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Pipeline\FragmentStore;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Docuccino\Laravel\Routing\LaravelRouteResolver;
use Docuccino\Laravel\Routing\ResolvedRouteIndex;
use Docuccino\Laravel\Routing\RouteSurvey;
use Docuccino\Laravel\Routing\VendorRoutePolicy;
use Docuccino\Laravel\Runtime\DocumentCache;
use Docuccino\Laravel\Versioning\Scaffold\ChangeStub;
use Docuccino\Laravel\Versioning\VersionChangeCollector;
use Docuccino\Laravel\Watch\ArtisanBuildRunner;
use Docuccino\Laravel\Watch\BuildRunner;
use Docuccino\Laravel\Watch\BuildToken;
use Docuccino\Laravel\Watch\WatchSet;
use Docuccino\Laravel\Watch\WatchSignal;
use Docuccino\Laravel\Webhooks\WebhookCollector;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

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
    /**
     * The released version of this package, published as `x-docuccino.generator.version` and keyed
     * into the fragment cache's tool version. Written by the release workflow, never by hand — see
     * RELEASING.md; the golden comparison normalises this one member, so a bump regenerates nothing.
     */
    public const string VERSION = '0.14.0';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('docuccino')
            ->hasConfigFile()
            ->hasCommands([
                InstallCommand::class,
                ExportCommand::class,
                ValidateCommand::class,
                DiffCommand::class,
                CacheCommand::class,
                ClearCommand::class,
                WatchCommand::class,
                ExplainCommand::class,
                CoverageCommand::class,
                VersionChangesCommand::class,
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
        // semantics); this is the boundary. The install command's route survey reads the same one, so
        // what it reports as documentable is what a build would actually document.
        $this->app->when([LaravelRouteResolver::class, RouteSurvey::class])
            ->needs(VendorRoutePolicy::class)
            ->give(fn (): VendorRoutePolicy => new VendorRoutePolicy($this->app->basePath('vendor')));

        // `docuccino:install` publishes the same file, from the same place, that
        // `vendor:publish --tag=docuccino-config` does.
        $this->app->bind(ConfigPublisher::class, fn (Application $app): ConfigPublisher => new ConfigPublisher(
            source: dirname(__DIR__).'/config/docuccino.php',
            target: $app->configPath('docuccino.php'),
        ));

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

        // #[Example(file: …)] reads a project file, confined to the same base #[Description(file: …)] is.
        $this->app->when(AttributeExamplesExtension::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        $this->app->when(TypeEngineFactory::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        $this->app->when(TypeEngineFactory::class)
            ->needs('$tmpDir')
            ->give(fn (): string => $this->app->storagePath('docuccino'));

        // Resolved (not captured) so it reads the marker as it stands when the engine is built — which is
        // after CommandStarting has run for one of our commands, and never during a web request.
        $this->app->when(TypeEngineFactory::class)
            ->needs('$console')
            ->give(static fn (): bool => ConsoleBuild::active());

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

        // The OperationFragment cache (design §10): filesystem, off by default. The store resolves the
        // directory once for both the cache that writes it and the command that clears it.
        $this->app->bind(FragmentStore::class, function (Application $app): FragmentStore {
            /** @var array<string, mixed> $cache */
            $cache = (array) config('docuccino.cache', []);
            $path = is_string($cache['path'] ?? null) ? $cache['path'] : $app->storagePath('docuccino/fragments');

            return new FragmentStore(
                enabled: (bool) ($cache['enabled'] ?? false),
                path: str_starts_with($path, '/') ? $path : $app->basePath($path),
            );
        });

        $this->app->bind(FragmentCache::class, function (Application $app): FragmentCache {
            $store = $app->make(FragmentStore::class);

            return new FragmentCache(
                enabled: $store->enabled,
                path: $store->path,
                toolVersion: self::VERSION.self::sourceReference(),
                specVersion: '1.0.0',
                identityVersion: 'v1',
            );
        });

        // What that cache keys on beyond config and routes: the engine that resolved, its
        // output-shaping config and the app's locked dependencies.
        $this->app->bind(BuildFingerprint::class, function (Application $app): BuildFingerprint {
            /** @var array<string, mixed> $engine */
            $engine = (array) config('docuccino.engine', []);

            return new BuildFingerprint($engine, $app->basePath(), $app->make(EnginePackage::class));
        });

        $this->app->when(DocumentBuilder::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        // `docuccino:watch`. The watch set reads the same fragment store a build writes, and the
        // engine bag is in because `engine.neon` is a file a build reads and nothing else watches.
        $this->app->bind(WatchSet::class, function (Application $app): WatchSet {
            /** @var array<string, mixed> $engine */
            $engine = (array) config('docuccino.engine', []);

            return new WatchSet(
                $app->make(DocumentBuilder::class),
                $app->make(FragmentStore::class),
                $app->basePath(),
                $engine,
            );
        });

        $this->app->when(BuildToken::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        // Under storage/, so it is already gitignored and already absent from a deployed release —
        // the reload endpoint exists only where a watch session put one here.
        $this->app->when(WatchSignal::class)
            ->needs('$path')
            ->give(fn (): string => $this->app->storagePath('docuccino/watch'));

        $this->app->bind(
            BuildRunner::class,
            fn (Application $app): BuildRunner => new ArtisanBuildRunner($app->basePath('artisan')),
        );

        $this->app->when(ContentCompiler::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        $this->app->when(WebhookCollector::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        $this->app->when(VersionChangeCollector::class)
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        // The data-leakage lint itself is framework-agnostic core; the adapter just maps
        // docuccino.lint.leakage.* onto its options.
        $this->app->bind(SensitiveFieldLint::class, static fn (): SensitiveFieldLint => new SensitiveFieldLint(
            LeakageOptions::fromConfig(self::leakageConfig()),
        ));

        // Recorded examples read the same heuristics table, minus its off switch: turning a report off
        // is not a request to publish credentials.
        $this->app->bind(ExampleRedaction::class, static fn (): ExampleRedaction => new ExampleRedaction(
            LeakageOptions::fromConfig(self::leakageConfig(), honourSwitch: false),
        ));

        $this->app->when([RecordedExampleAudit::class, RecordedExamplesExtension::class])
            ->needs('$basePath')
            ->give(fn (): string => $this->app->basePath());

        // The completeness lints share one options shape, so they share one reader; each is core, and
        // the adapter only maps its docuccino.lint.<key> bag onto it.
        $this->app->bind(MissingDescriptionLint::class, static fn (): MissingDescriptionLint => new MissingDescriptionLint(self::lintRule('descriptions', false)));
        $this->app->bind(OperationIdStyleLint::class, static fn (): OperationIdStyleLint => new OperationIdStyleLint(self::lintRule('operation_ids', true)));
        $this->app->bind(UndocumentedTagLint::class, static fn (): UndocumentedTagLint => new UndocumentedTagLint(self::lintRule('tags', false)));
        $this->app->bind(VacuousUnionLint::class, static fn (): VacuousUnionLint => new VacuousUnionLint(self::lintRule('vacuous_union', true)));
        $this->app->bind(ExampleSchemaLint::class, static fn (): ExampleSchemaLint => new ExampleSchemaLint(self::lintRule('examples', true)));
        $this->app->bind(UnpinnedRedirectLint::class, static fn (): UnpinnedRedirectLint => new UnpinnedRedirectLint(self::lintRule('unpinned_redirect', true)));

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
        $this->app->bind(QueryBuilderParametersExtension::class, static function (Application $app): QueryBuilderParametersExtension {
            /** @var array<string, mixed> $config */
            $config = (array) config('query-builder', []);
            // That integration picks its own extra trace roots off an action's type hints, so it needs the
            // same vendor boundary the route filter uses to refuse a package-shipped builder subclass.
            $vendor = new VendorRoutePolicy($app->basePath('vendor'));

            return new QueryBuilderParametersExtension(
                QueryBuilderConfig::fromArray($config, self::spatieQueryBuilderMajor()),
                isVendorFile: $vendor->isVendorFile(...),
            );
        });

        $this->app->bind(QueryBuilderConfigDigestContributor::class, static function (): QueryBuilderConfigDigestContributor {
            /** @var array<string, mixed> $config */
            $config = (array) config('query-builder', []);

            return new QueryBuilderConfigDigestContributor(QueryBuilderConfig::fromArray($config, self::spatieQueryBuilderMajor()));
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
            return new DataSchema(
                reflector: $app->make(DataClassReflector::class),
                dateFormat: self::dataDateFormat(),
                wrap: new WrapResolver(self::stringConfig('data.wrap')),
            );
        });

        // The request side is handed the SAME `data.date_format`, so a DateTimeInterface property
        // documents one wire format in both directions.
        $this->app->bind(DataValidationRules::class, function (Application $app): DataValidationRules {
            return new DataValidationRules(
                reflector: $app->make(DataClassReflector::class),
                dateFormat: self::dataDateFormat(),
            );
        });

        // Resolved from the container so tests (and users) can swap in a stub or the NullTypeEngine;
        // otherwise built from config, degrading to null on boot failure. Deferred, because commands
        // method-inject a TypeEngine and a fully cached build never asks it anything (LazyTypeEngine).
        $this->app->bind(TypeEngine::class, static function (Application $app): TypeEngine {
            /** @var array<string, mixed> $config */
            $config = (array) config('docuccino.engine', []);

            return $app->make(TypeEngineFactory::class)->deferred($config);
        });
    }

    /**
     * The installed source reference of this package (a commit hash for a dev/path checkout), for the
     * fragment cache's tool version ONLY — never for the generator version, which names a release and
     * would otherwise publish a commit hash to whoever reads the document.
     * The app's `composer.lock` already keys the cache, so a released upgrade invalidates fragments;
     * a checkout of Docuccino's own source does not appear there at all, and that is the maintainer's
     * dev loop. Empty when Composer's runtime API can't answer, which is back to keying on the version
     * alone.
     */
    private static function sourceReference(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return '';
        }

        try {
            $reference = InstalledVersions::getReference('docuccino/laravel');
        } catch (Throwable) {
            return '';
        }

        return is_string($reference) ? '@'.$reference : '';
    }

    /**
     * The installed spatie/laravel-query-builder major, off Composer's runtime API. No extra cache
     * keying: the app's composer.lock is already in {@see BuildFingerprint}, so an upgrade retires
     * warm fragments on its own.
     */
    private static function spatieQueryBuilderMajor(): int
    {
        if (! class_exists(InstalledVersions::class)) {
            return QueryBuilderConfig::majorOf(null);
        }

        try {
            return QueryBuilderConfig::majorOf(InstalledVersions::getVersion('spatie/laravel-query-builder'));
        } catch (Throwable) {
            return QueryBuilderConfig::majorOf(null);
        }
    }

    /** The app's spatie-data date format, or the package's own default where it is unset. */
    private static function dataDateFormat(): string
    {
        return self::stringConfig('data.date_format') ?? DateWireFormat::DEFAULT_FORMAT;
    }

    /** A non-empty string config value, or null when unset/blank. */
    private static function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function leakageConfig(): array
    {
        /** @var array<string, mixed> $leakage */
        $leakage = (array) config('docuccino.lint.leakage', []);

        return $leakage;
    }

    /**
     * One `docuccino.lint.<key>` bag as rule options. `$default` is the rule's own answer when the key
     * is absent, so a config file predating the rule keeps whatever shipped with it.
     */
    private static function lintRule(string $key, bool $default): LintRuleOptions
    {
        /** @var array<string, mixed> $rule */
        $rule = (array) config('docuccino.lint.'.$key, []);
        $allow = is_array($rule['allow'] ?? null) ? array_values(array_filter($rule['allow'], 'is_string')) : [];

        return new LintRuleOptions(
            enabled: ($rule['enabled'] ?? $default) !== false,
            allow: $allow,
        );
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
            $entry = self::passportScopeEntry($scope);
            if ($entry !== null) {
                $scopes[$entry[0]] = $entry[1];
            }
        }

        return new PassportRuntime(
            $scopes,
            Passport::$passwordGrantEnabled === true,
            Passport::$implicitGrantEnabled === true,
        );
    }

    /**
     * One scope catalogue entry as `[id, description]`, or null for anything else. Takes `mixed` on
     * purpose: Passport 13 types `Passport::scopes()` as a `Scope` collection while 12 leaves the
     * element type open, so the narrowing has to be meaningful under both.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function passportScopeEntry(mixed $scope): ?array
    {
        return $scope instanceof Scope ? [$scope->id, $scope->description] : null;
    }

    /**
     * Registers the runtime viewer routes for each document with a `viewer.route`: the HTML page, its
     * `.json` spec, the active driver's assets, and the reload channel `docuccino:watch` refreshes an
     * open page through. Access control lives in {@see DocsController} (a `viewer.gate` ability, else
     * local env only; reload additionally 404s with no watch session running), which is why the asset
     * route is one pattern rather than one per driver — the driver is chosen late, from a registry
     * that is not readable at boot, and the names it serves are its own allow-list.
     */
    public function packageBooted(): void
    {
        // Ahead of the off-switch: `--memory-limit` has to reach config before a command's dependencies
        // resolve the engine, and this listener is the last hook early enough (see MemoryLimitOption).
        Event::listen(CommandStarting::class, MemoryLimitOption::capture(...));

        // The scaffold template, publishable the way every other publishable file in Laravel is. Not on
        // `docuccino:install`: that command runs once and idempotently, while editing a stub is a
        // repeatable act somebody takes when they want their own — so it rides `vendor:publish`, which
        // is where an author already looks, exactly as `--tag=docuccino-config` does for the config.
        $this->publishes(
            [ChangeStub::packaged() => $this->app->basePath(ChangeStub::PUBLISHED_DIRECTORY.'/'.ChangeStub::NAME)],
            'docuccino-stubs',
        );

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
            Route::get($base.'/assets/{asset}.js', [DocsController::class, 'asset'])
                ->middleware($middleware)
                ->where('asset', '[A-Za-z0-9_-]+')
                ->defaults('document', (string) $key);
            Route::get($base.'/reload', [DocsController::class, 'reload'])->middleware($middleware)->defaults('document', (string) $key);
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
