<?php

declare(strict_types=1);

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Contracts\RouteFilter;
use Docuccino\Laravel\Config\ConfiguredRouteFilter;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Config\UnusableRouteFilterException;
use Docuccino\Laravel\Registry\ConfigDiagnostics;
use Docuccino\Laravel\Tests\Fixtures\RouteFilters\AllowListFilter;
use Docuccino\Laravel\Tests\Fixtures\RouteFilters\DocumentedPaths;
use Docuccino\Laravel\Tests\Fixtures\RouteFilters\NamedRoutes;
use Docuccino\Laravel\Tests\Fixtures\RouteFilters\NotAFilter;
use Docuccino\Laravel\Tests\Fixtures\RouteFilters\UnbuildableFilter;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 4).'/tools/config-reference-sync.php';

/*
 * `routes.filter` — the container-resolved RouteFilter that narrows a document past the
 * include/exclude wildcards.
 */

afterEach(function (): void {
    removeFragmentCacheDirs('route-filter');
});

/**
 * The default document's settings with `routes.filter` set to whatever the caller is testing, written
 * as configuration and read back the way the product reads it.
 *
 * @return array<string, mixed>
 */
function routeFilterSettings(mixed $filter = null): array
{
    if ($filter !== null) {
        setBuild('documents.default.routes.filter', $filter);
    }

    return documentSettings();
}

function resolvedRouteFilter(mixed $filter = null): ?RouteFilter
{
    return app(DocumentConfigFactory::class)
        ->make('default', routeFilterSettings($filter), 'skeleton')
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

    setBuild('documents.default.routes.filter', AllowListFilter::class);

    $document = stubDocumentArray();

    expect(array_keys($document['paths']))->toBe(['/api/forms']);
});

it('documents every wildcard-admitted route when the key is unset', function (): void {
    $config = app(DocumentConfigFactory::class)->make('default', documentSettings(), 'skeleton');

    expect($config->routeFilter)->toBeNull()
        // And says nothing about it: a document that narrows nothing is the shipped shape, so a
        // diagnostic here would fire on every fresh install.
        ->and(ConfigDiagnostics::for($config))->toBe([]);
});

it('asks the filter on a warm build, not only on a cold one', function (): void {
    // The filter decides DISCOVERY, before any fragment is looked up, so its answer is never cached —
    // which is why the filter class's file is not a fragment-cache dependency. The class-string here
    // never changes, so `configHash` is untouched and every fragment stays warm; the document still
    // has to follow the filter's new answer on the very next build.
    fragmentCacheDir('route-filter');
    bindStubEngine();
    setBuild('documents.default.routes.filter', AllowListFilter::class);
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
        ->and($body)->toContain($decision)
        // And the key is quoted in the format the build reads it from: a PHP-shaped snippet would
        // send a reader to a file that no longer holds the setting.
        ->and($page)->toContain('filter: App\\Docs\\NamedRoutes');
});

it('refuses the build rather than publishing a route set the filter was there to narrow', function (
    mixed $filter,
    string $message,
): void {
    try {
        resolvedRouteFilter($filter);
    } catch (UnusableRouteFilterException $refusal) {
        expect($refusal->diagnostic->severity)->toBe(Severity::Error)
            ->and($refusal->diagnostic->code)->toBe('config.route-filter-unusable')
            ->and($refusal->diagnostic->message)->toBe($message)
            ->and($refusal->diagnostic->help)->toContain('Point documents.default.routes.filter at an autoloadable class')
            // The exception reads the same as the diagnostic, so the surfaces that let it through say
            // what the ones that render it say.
            ->and($refusal->getMessage())->toBe($message);

        return;
    }

    $this->fail('a filter that cannot be applied has to stop the run');
})->with([
    'filter is not a class-string' => [
        ['App\\Docs\\PublicRoutes'],
        'documents.default.routes.filter is array rather than the name of a class implementing Docuccino\Core\Extensions\Contracts\RouteFilter.',
    ],
    'filter is an empty string' => [
        '   ',
        "documents.default.routes.filter is '   ' rather than the name of a class implementing Docuccino\Core\Extensions\Contracts\RouteFilter.",
    ],
    'filter names no autoloadable class' => [
        'App\\Docs\\NoSuchFilter',
        "documents.default.routes.filter names 'App\\Docs\\NoSuchFilter', which is not an autoloadable class.",
    ],
    'filter does not implement the contract' => [
        NotAFilter::class,
        "documents.default.routes.filter names 'Docuccino\Laravel\Tests\Fixtures\RouteFilters\NotAFilter', which does not implement Docuccino\Core\Extensions\Contracts\RouteFilter.",
    ],
    'filter throws while the container builds it' => [
        UnbuildableFilter::class,
        "documents.default.routes.filter names 'Docuccino\Laravel\Tests\Fixtures\RouteFilters\UnbuildableFilter', which the container could not build: the tenant registry is not configured.",
    ],
]);

it('shows the key without shipping it, and ships no key a file cannot hold', function (): void {
    // Both halves of the fingerprint rule, at the file. The key has to be VISIBLE or nobody finds it,
    // and it has to be ABSENT from what parses or every document's `configHash` carries a setting
    // nobody turned on. There is also no `closure` key: a configuration file has no form for a
    // callable, so the only thing shipping one could do is invite a value nothing would read.
    $file = (string) file_get_contents(
        dirname(__DIR__, 2).'/config/'.ConfigFile::NAME,
    );

    /** @var array<string, mixed> $parsed */
    $parsed = Yaml::parse($file, ConfigFile::FLAGS);
    /** @var array<string, mixed> $routes */
    $routes = data_get($parsed, 'documents.default.routes', []);

    // Commented-out keys included, which is what makes the first assertion say "shown".
    $declared = config_reference_yaml_keys($file);

    // The document key normalizes to `*`: it is a name an application chooses, not a key.
    expect($declared)->toContain('documents.*.routes.filter')
        ->and($declared)->not->toContain('documents.*.routes.closure')
        ->and(array_keys($routes))->toBe(['include', 'exclude', 'include_vendor']);
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

it('escapes the document key in the refusal, the way it already escapes the class', function (mixed $filter): void {
    // The class name and the container's own failure already go through `PlainText` here, and the
    // document key beside them did not. It is not covered by the console renderer's escape either: the
    // refusal is an EXCEPTION, and `getMessage()` is what a caller that lets it through prints.
    try {
        app(ConfiguredRouteFilter::class)->resolve("ev\x1b[31mil", ['filter' => $filter]);
    } catch (UnusableRouteFilterException $refusal) {
        expect($refusal->getMessage())->not->toContain("\x1b")
            ->and($refusal->getMessage())->toContain('\x1B')
            ->and($refusal->diagnostic->help)->not->toContain("\x1b")
            ->and($refusal->diagnostic->help)->toContain('\x1B');

        return;
    }

    $this->fail('a filter that cannot be applied has to stop the run');
})->with([
    // Every refusal, so the key is not hardened one arm at a time.
    'not a class-string' => [['App\Docs\PublicRoutes']],
    'not autoloadable' => ['App\Docs\NoSuchFilter'],
    'the container could not build it' => [UnbuildableFilter::class],
    'not a route filter' => [NotAFilter::class],
]);

it('reports an unusable filter as a config error and writes nothing', function (): void {
    $out = sys_get_temp_dir().'/docuccino-route-filter-'.uniqid().'.json';
    setBuild('documents.default.routes.filter', 'App\\Docs\\NoSuchFilter');

    // One substring per written line: the diagnostic's own line, then the `help` under it.
    $this->artisan('docuccino:export', ['--out' => $out])
        ->expectsOutputToContain("[error] config.route-filter-unusable: documents.default.routes.filter names 'App\\Docs\\NoSuchFilter', which is not an autoloadable class.")
        ->expectsOutputToContain('Point documents.default.routes.filter at an autoloadable class implementing')
        ->assertFailed();

    expect(file_exists($out))->toBeFalse();
});
