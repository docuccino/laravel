<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\ViewerContext;
use Docuccino\Core\Extensions\Contracts\Viewer;
use Docuccino\Core\Extensions\Contracts\ViewerAssets;
use Docuccino\Core\Extensions\Contracts\ViewerSpecVersion;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Docuccino\Laravel\Viewer\DefaultViewers;
use Docuccino\Laravel\Viewer\RedocViewer;
use Docuccino\Laravel\Viewer\ScalarViewer;
use Docuccino\Laravel\Viewer\ViewerDrivers;

/**
 * The shipped driver table. Every entry is walked, because a driver that is listed but does not
 * resolve — or whose bundle is not in the package — is a 404 nobody finds until a reader hits it.
 */
it('ships a driver that resolves, names itself and serves its own bundle', function (string $class): void {
    $viewer = app($class);

    expect($viewer)->toBeInstanceOf(Viewer::class)
        // A driver id goes in config by hand, so it stays lowercase and hyphenated.
        ->and($viewer->name())->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/');

    // Both shipped drivers serve their script from the package, which is what keeps choosing one from
    // quietly making the docs page depend on the network.
    expect($viewer)->toBeInstanceOf(ViewerAssets::class);

    foreach ($viewer->assets() as $name => $path) {
        expect($name)->toMatch('/^[A-Za-z0-9_-]+$/')
            ->and(is_file($path))->toBeTrue();
    }
})->with(DefaultViewers::all());

it('offers each shipped driver under exactly one name, the default among them', function (): void {
    $names = array_keys(app(ExtensionRegistry::class)->viewers(app(), DefaultViewers::all(), []));

    expect($names)->toHaveCount(count(DefaultViewers::all()))
        ->and($names)->toContain(DefaultViewers::DEFAULT);
});

it('merges a driver from config extensions alongside the built-ins', function (): void {
    $viewers = app(ExtensionRegistry::class)->viewers(app(), DefaultViewers::all(), [new class implements Viewer
    {
        public function name(): string
        {
            return 'from-config';
        }

        public function render(ViewerContext $context): string
        {
            return '';
        }
    }]);

    expect(array_keys($viewers))->toContain('from-config', DefaultViewers::DEFAULT);
});

// The bundled Redoc parses a 3.2 document by aliasing it to 3.1, silently dropping 3.2-only
// semantics — so the driver declares the version its bundle actually implements, and the shipped
// bundle is held to accepting it. A swapped asset that stops accepting what we serve fails here.
it('declares 3.1 for Redoc and proves the shipped bundle accepts it', function (): void {
    $redoc = new RedocViewer;

    expect($redoc)->toBeInstanceOf(ViewerSpecVersion::class)
        ->and($redoc->specVersion())->toBe('3.1');

    $bundle = (string) file_get_contents($redoc->assets()['redoc']);

    // A plausible minimum, so an emptied or truncated asset fails rather than vacuously passing.
    expect(strlen($bundle))->toBeGreaterThan(100_000)
        ->and(str_contains($bundle, 'startsWith("3.1")'))->toBeTrue();
});

it('serves Scalar the newest format — it declares no version of its own', function (): void {
    expect(new ScalarViewer)->not->toBeInstanceOf(ViewerSpecVersion::class);
});

// Every endpoint that feeds a viewer picks its emitter through this one seam.
it('picks the emitter a document\'s driver implements', function (?string $driver, string $format): void {
    $drivers = new ViewerDrivers(app(ExtensionRegistry::class), app());
    $config = new DocumentConfig('default', [], viewer: $driver === null ? [] : ['driver' => $driver]);

    expect($drivers->emitterFor($config)->format())->toBe($format)
        // The format the runtime cache keys an entry by is the one the seam emits.
        ->and($drivers->formatFor($config))->toBe($format);
})->with([
    'redoc downlevels to 3.1' => ['redoc', 'openapi-3.1'],
    'scalar gets 3.2' => ['scalar', 'openapi-3.2'],
    'no driver configured gets the default driver\'s 3.2' => [null, 'openapi-3.2'],
]);

/** A stub driver named `stub`, declaring the given spec version — or none, when it is null. */
$versionedViewer = static function (?string $version): Viewer {
    if ($version === null) {
        return new class implements Viewer
        {
            public function name(): string
            {
                return 'stub';
            }

            public function render(ViewerContext $context): string
            {
                return '';
            }
        };
    }

    return new class($version) implements Viewer, ViewerSpecVersion
    {
        public function __construct(private readonly string $version) {}

        public function name(): string
        {
            return 'stub';
        }

        public function render(ViewerContext $context): string
        {
            return '';
        }

        public function specVersion(): string
        {
            return $this->version;
        }
    };
};

it('serves every declared spec version its own emitter, and an unknown one the newest', function (?string $declared, string $format) use ($versionedViewer): void {
    // `docuccino.extensions` takes an instance as readily as a class-string, so the stub goes in whole.
    config()->set('docuccino.extensions', [$versionedViewer($declared)]);

    $drivers = new ViewerDrivers(app(ExtensionRegistry::class), app());
    $config = new DocumentConfig('default', [], viewer: ['driver' => 'stub']);

    expect($drivers->emitterFor($config)->format())->toBe($format);
})->with([
    'a 3.0 driver downlevels all the way' => ['3.0', 'openapi-3.0'],
    'a 3.1 driver downlevels one minor' => ['3.1', 'openapi-3.1'],
    'a 3.2 driver is served as emitted' => ['3.2', 'openapi-3.2'],
    // Degradation: an unrecognised version is not a reason to serve something older than the document.
    'an unknown version gets the newest' => ['4.0', 'openapi-3.2'],
    'a nonsense version gets the newest' => ['', 'openapi-3.2'],
    'a driver declaring no version at all gets the newest' => [null, 'openapi-3.2'],
]);

// A CDN reference that floats can drift to a build whose behavior nothing here has verified; both
// shipped drivers pin at least the major.
it('pins the CDN reference of every shipped driver', function (string $class): void {
    $cdn = (new ReflectionClassConstant($class, 'CDN_SRC'))->getValue();

    expect($cdn)->toMatch('~@\d~');
})->with(DefaultViewers::all());
