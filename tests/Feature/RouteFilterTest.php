<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\RouteFilter;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Config\UnusableRouteFilterException;
use Docuccino\Laravel\Registry\ConfigDiagnostics;
use Docuccino\Laravel\Tests\Fixtures\RouteFilters\AllowListFilter;
use Docuccino\Laravel\Tests\Fixtures\RouteFilters\DocumentedPaths;
use Docuccino\Laravel\Tests\Fixtures\RouteFilters\NamedRoutes;
use Docuccino\Laravel\Tests\Fixtures\RouteFilters\NotAFilter;
use Docuccino\Laravel\Tests\Fixtures\RouteFilters\UnbuildableFilter;
use Illuminate\Foundation\Console\ConfigCacheCommand;

/*
 * `routes.filter` — the container-resolved RouteFilter that narrows a document past the
 * include/exclude wildcards — and `routes.closure`, the removed key it replaces.
 */

afterEach(function (): void {
    removeFragmentCacheDirs('route-filter');
});

/**
 * One document's raw config with both route-filter keys set to whatever the caller is testing.
 *
 * @return array<string, mixed>
 */
function routeFilterConfig(mixed $filter = null, mixed $closure = null): array
{
    /** @var array<string, mixed> $raw */
    $raw = config('docuccino.documents.default');
    /** @var array<string, mixed> $routes */
    $routes = $raw['routes'] ?? [];

    return [...$raw, 'routes' => [...$routes, 'filter' => $filter, 'closure' => $closure]];
}

function resolvedRouteFilter(mixed $filter = null, mixed $closure = null): ?RouteFilter
{
    return app(DocumentConfigFactory::class)
        ->make('default', routeFilterConfig($filter, $closure), 'skeleton')
        ->routeFilter;
}

function routeAt(string $uri): RouteDescriptor
{
    return new RouteDescriptor(methods: ['GET'], uri: $uri, name: null, action: 'C@index');
}

it('narrows the document with a filter the container built', function (): void {
    // The dependency is bound, not default-constructed, so the emitted paths can only be this narrow
    // if the instance the container held is the one the filter answered from.
    app()->instance(DocumentedPaths::class, new DocumentedPaths(['/api/forms']));

    $document = stubDocumentArray(static fn (array $raw): array => [
        ...$raw,
        'routes' => [...(array) ($raw['routes'] ?? []), 'filter' => AllowListFilter::class],
    ]);

    expect(array_keys($document['paths']))->toBe(['/api/forms']);
});

it('documents every wildcard-admitted route when the key is unset', function (): void {
    expect(resolvedRouteFilter())->toBeNull();
});

it('asks the filter on a warm build, not only on a cold one', function (): void {
    // The filter decides DISCOVERY, before any fragment is looked up, so its answer is never cached —
    // which is why the filter class's file is not a fragment-cache dependency. The class-string here
    // never changes, so `configHash` is untouched and every fragment stays warm; the document still
    // has to follow the filter's new answer on the very next build.
    fragmentCacheDir('route-filter');
    bindStubEngine();
    config()->set('docuccino.documents.default.routes.filter', AllowListFilter::class);
    app()->instance(DocumentedPaths::class, new DocumentedPaths(['/api/forms', '/api/ping']));

    $cold = generateDocument();
    $coldPaths = array_keys($cold->document->toArray()['paths']);

    app()->instance(DocumentedPaths::class, new DocumentedPaths(['/api/forms']));
    $warm = generateDocument();

    expect($coldPaths)->toBe(['/api/forms', '/api/ping'])
        ->and(array_keys($warm->document->toArray()['paths']))->toBe(['/api/forms'])
        // The route that survived is served warm, byte-identically: nothing about the filter's change
        // touches what a kept operation says.
        ->and((new UirEmitter)->emit($warm->document))->toContain('/api/forms');
});

it('runs the worked example the multiple-documents guide prints', function (): void {
    // The guide's snippet is not illustration, it is a class a reader will paste. So it exists as a
    // fixture that the suite executes, and the page is held to that fixture's body — an example
    // nobody runs is one that stops working without anybody finding out.
    $filter = resolvedRouteFilter(NamedRoutes::class);

    expect($filter)->toBeInstanceOf(NamedRoutes::class)
        ->and($filter?->includes(new RouteDescriptor(methods: ['GET'], uri: '/api/v2/forms', name: 'forms.index')))->toBeTrue()
        ->and($filter?->includes(routeAt('/api/v2/forms')))->toBeFalse();

    $page = (string) file_get_contents(
        dirname(__DIR__, 4).'/website/src/content/docs/laravel/guides/multiple-documents.mdx',
    );
    $body = (string) file_get_contents(
        (string) (new ReflectionClass(NamedRoutes::class))->getFileName(),
    );

    $shown = 'public function includes(RouteDescriptor $route): bool';
    $decision = 'return $route->name !== null;';

    expect($page)->toContain('class NamedRoutes implements RouteFilter')
        ->and($page)->toContain($shown)
        ->and($page)->toContain($decision)
        // Both halves, so the page cannot drift from the class the assertions above proved.
        ->and($body)->toContain($shown)
        ->and($body)->toContain($decision);
});

it('refuses the build rather than publishing a route set the filter was there to narrow', function (
    mixed $filter,
    mixed $closure,
    string $code,
    string $message,
    string $help,
): void {
    try {
        resolvedRouteFilter($filter, $closure);
    } catch (UnusableRouteFilterException $refusal) {
        expect($refusal->diagnostic->severity)->toBe(Severity::Error)
            ->and($refusal->diagnostic->code)->toBe($code)
            ->and($refusal->diagnostic->message)->toBe($message)
            ->and($refusal->diagnostic->help)->toContain($help)
            // The exception reads the same as the diagnostic, so the surfaces that let it through say
            // what the ones that render it say.
            ->and($refusal->getMessage())->toBe($message);

        return;
    }

    $this->fail('a filter that cannot be applied has to stop the run');
})->with([
    'filter is not a class-string' => [
        ['App\\Docs\\PublicRoutes'],
        null,
        'config.route-filter-unusable',
        'documents.default.routes.filter is array rather than the name of a class implementing Docuccino\Core\Extensions\Contracts\RouteFilter.',
        'Point documents.default.routes.filter at an autoloadable class',
    ],
    'filter is an empty string' => [
        '   ',
        null,
        'config.route-filter-unusable',
        "documents.default.routes.filter is '   ' rather than the name of a class implementing Docuccino\Core\Extensions\Contracts\RouteFilter.",
        'Point documents.default.routes.filter at an autoloadable class',
    ],
    'filter names no autoloadable class' => [
        'App\\Docs\\NoSuchFilter',
        null,
        'config.route-filter-unusable',
        "documents.default.routes.filter names 'App\\Docs\\NoSuchFilter', which is not an autoloadable class.",
        'Point documents.default.routes.filter at an autoloadable class',
    ],
    'filter does not implement the contract' => [
        NotAFilter::class,
        null,
        'config.route-filter-unusable',
        "documents.default.routes.filter names 'Docuccino\Laravel\Tests\Fixtures\RouteFilters\NotAFilter', which does not implement Docuccino\Core\Extensions\Contracts\RouteFilter.",
        'Point documents.default.routes.filter at an autoloadable class',
    ],
    'filter throws while the container builds it' => [
        UnbuildableFilter::class,
        null,
        'config.route-filter-unusable',
        "documents.default.routes.filter names 'Docuccino\Laravel\Tests\Fixtures\RouteFilters\UnbuildableFilter', which the container could not build: the tenant registry is not configured.",
        'Point documents.default.routes.filter at an autoloadable class',
    ],
    'the removed closure key holds a closure' => [
        null,
        static fn (RouteDescriptor $route): bool => true,
        'config.route-closure-removed',
        'documents.default.routes.closure is set to Closure, and that key is no longer read.',
        'name it under documents.default.routes.filter',
    ],
    'the removed closure key holds a class name' => [
        null,
        'App\\Docs\\PublicRoutes',
        'config.route-closure-removed',
        "documents.default.routes.closure is set to 'App\\Docs\\PublicRoutes', and that key is no longer read.",
        'name it under documents.default.routes.filter',
    ],
    'the removed closure key refuses even beside a working filter' => [
        AllowListFilter::class,
        static fn (RouteDescriptor $route): bool => true,
        'config.route-closure-removed',
        'documents.default.routes.closure is set to Closure, and that key is no longer read.',
        'name it under documents.default.routes.filter',
    ],
]);

it('says nothing about a null left behind under the removed key', function (): void {
    // An application that published the config before the key went, and never used it, has nothing to
    // migrate. Refusing over a line its owner never filled in would be refusing over nothing.
    $config = app(DocumentConfigFactory::class)->make('default', routeFilterConfig(), 'skeleton');

    expect($config->routeFilter)->toBeNull()
        ->and(ConfigDiagnostics::for($config))->toBe([]);
});

it('ships no closure key for an application to fill in', function (): void {
    /** @var array<string, mixed> $shipped */
    $shipped = require dirname(__DIR__, 2).'/config/docuccino.php';
    /** @var array<string, mixed> $routes */
    $routes = data_get($shipped, 'documents.default.routes', []);

    expect(array_key_exists('closure', $routes))->toBeFalse()
        ->and(array_key_exists('filter', $routes))->toBeFalse();
});

it('keeps the cause of a construction failure attached to the refusal', function (): void {
    // The class's own error is what tells the reader WHICH dependency is missing, so it travels as the
    // previous exception rather than being replaced by ours.
    try {
        resolvedRouteFilter(UnbuildableFilter::class);
    } catch (UnusableRouteFilterException $refusal) {
        expect($refusal->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($refusal->getPrevious()?->getMessage())->toBe('the tenant registry is not configured');

        return;
    }

    $this->fail('a constructor that throws has to stop the run');
});

it('reports an unusable filter as a config error and writes nothing', function (): void {
    $out = sys_get_temp_dir().'/docuccino-route-filter-'.uniqid().'.json';
    config()->set('docuccino.documents.default.routes.filter', 'App\\Docs\\NoSuchFilter');

    // One substring per written line: the diagnostic's own line, then the `help` under it.
    $this->artisan('docuccino:export', ['--out' => $out])
        ->expectsOutputToContain("[error] config.route-filter-unusable: documents.default.routes.filter names 'App\\Docs\\NoSuchFilter', which is not an autoloadable class.")
        ->expectsOutputToContain('Point documents.default.routes.filter at an autoloadable class implementing')
        ->assertFailed();

    expect(file_exists($out))->toBeFalse();
});

it('reports the removed closure key as a config error and writes nothing', function (): void {
    $out = sys_get_temp_dir().'/docuccino-route-closure-'.uniqid().'.json';
    config()->set('docuccino.documents.default.routes.closure', static fn (RouteDescriptor $route): bool => true);

    $this->artisan('docuccino:export', ['--out' => $out])
        ->expectsOutputToContain('[error] config.route-closure-removed: documents.default.routes.closure is set to Closure, and that key is no longer read.')
        ->expectsOutputToContain('Move the predicate into a class implementing')
        ->assertFailed();

    expect(file_exists($out))->toBeFalse();
});

it('keeps a filter class-string cacheable where a closure was not', function (): void {
    // Why the key is gone rather than deprecated. `config:cache` serializes the whole config array
    // with `var_export()` and requires the file back (ConfigCacheCommand::handle), then, on failure,
    // re-exports each dotted value to name the one that broke. The command itself cannot run in this
    // harness — it re-bootstraps a fresh application from the testbench skeleton, which never
    // registers this package, so the config under test is not in the array it caches — so the step it
    // fails at is exercised directly here, and the test below pins that this IS still the step.
    $withFilter = ['filter' => AllowListFilter::class];
    $withClosure = ['closure' => static fn (): bool => true];

    /** @var array<string, mixed> $cached */
    $cached = eval('return '.var_export($withFilter, true).';');

    expect($cached)->toBe($withFilter)
        // What `php artisan config:cache` reported for the same value: "Your configuration files
        // could not be serialized because the value at documents.default.routes.closure is
        // non-serializable."
        ->and(static fn (): mixed => eval('return '.var_export($withClosure, true).';'))
        ->toThrow(Error::class, 'Call to undefined method Closure::__set_state()');
});

it('reads config:cache as still serializing the config with var_export', function (): void {
    // The premise of the test above, and of the removal itself. If the framework ever serializes
    // config some other way, this fails and the claim gets re-checked rather than repeated.
    $file = (new ReflectionClass(ConfigCacheCommand::class))->getFileName();

    expect((string) file_get_contents((string) $file))->toContain('var_export($config, true)');
});
