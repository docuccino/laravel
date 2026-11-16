<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\FleetVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\MutableVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\OverridingVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\PaintedVanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\VanData;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\PlateNormalizer;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Van;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\VanController;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Normalizers\ModelNormalizer;
use Spatie\LaravelData\Normalizers\ObjectNormalizer;

/**
 * Response links from a Data body: a response built from a model with laravel-data, linked to the
 * operations whose path parameters are matched on a column it copies. Which construction a body came from
 * is read where the action builds it (the walk is scripted here; the real engine's reading of each spelling
 * is proved in `DataSourceVisitorTest`), and what laravel-data makes of it in `DataColumnsTest`. These rows
 * prove the two meet the join and land on the member the published body documents.
 */
beforeEach(function (): void {
    $this->routes = static function (Router $router): void {
        $router->post('api/vans', [VanController::class, 'store']);
        $router->get('api/vans/{van}', [VanController::class, 'show']);
        $router->get('api/plated-vans/{van:plate}', [VanController::class, 'byPlate']);
        $router->post('api/fleet', [VanController::class, 'enlist']);
        $router->post('api/fleet/in-order', [VanController::class, 'enlistInOrder']);
        $router->post('api/unwrapped-vans', [VanController::class, 'unwrapped']);
        $router->post('api/painted-vans', [VanController::class, 'painted']);
        $router->post('api/retitled-vans', [VanController::class, 'retitled']);
        $router->post('api/demonstration-vans', [VanController::class, 'demonstrated']);
        $router->post('api/described-vans', [VanController::class, 'described']);
    };

    $this->engine = static function (): TypeEngine {
        $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))]);
        $controller = VanController::class.'::';
        $file = dirname(__DIR__).'/Fixtures/ResponseLinks/VanController.php';
        $types = [
            'van' => new ClassT(Van::class),
            'data' => new ClassT(VanData::class),
            'copy' => new ClassT(MutableVanData::class),
            // A template bound to the base class reaches the walk as that class.
            'meta' => new ClassT(Data::class),
        ];
        $walk = static fn (string $method): callable => TraceScript::forMethod($file, VanController::class, $method, $types);
        // The walk descends into the helper `described()` hands its object to, as the engine's does.
        $described = static function (TraceVisitor $visitor) use ($walk): void {
            $walk('described')($visitor);
            $walk('withMeta')($visitor);
        };
        $methods = ['store' => VanData::class, 'show' => VanData::class, 'byPlate' => VanData::class, 'enlist' => FleetVanData::class, 'enlistInOrder' => FleetVanData::class, 'unwrapped' => VanData::class, 'painted' => PaintedVanData::class, 'retitled' => MutableVanData::class, 'demonstrated' => OverridingVanData::class, 'described' => VanData::class];
        $walks = array_combine(array_keys($methods), array_map($walk, array_keys($methods)));
        $walks['described'] = $described;

        return WorkbenchEngine::make(
            classOverrides: [
                Van::class => new ClassMetadata(Van::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('plate', ScalarT::string()),
                    new PropertyMetadata('fleet_number', ScalarT::string()),
                    new PropertyMetadata('colour', ScalarT::string()),
                ]),
                VanData::class => new ClassMetadata(VanData::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('plate', ScalarT::string()),
                    new PropertyMetadata('colour', ScalarT::string()),
                ]),
                FleetVanData::class => new ClassMetadata(FleetVanData::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('fleetNumber', ScalarT::string()),
                    new PropertyMetadata('reg', ScalarT::string()),
                ]),
                MutableVanData::class => new ClassMetadata(MutableVanData::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('plate', ScalarT::string()),
                ]),
                OverridingVanData::class => new ClassMetadata(OverridingVanData::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('plate', ScalarT::string()),
                ]),
                PaintedVanData::class => new ClassMetadata(PaintedVanData::class, [
                    new PropertyMetadata('vin', ScalarT::string()),
                    new PropertyMetadata('plate', ScalarT::string()),
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('model_name', ScalarT::string()),
                    new PropertyMetadata('make', ScalarT::string()),
                    new PropertyMetadata('mileage', UnionT::of([ScalarT::int(), new NullT])),
                    new PropertyMetadata('weight', ScalarT::float()),
                ]),
            ],
            analysisOverrides: array_combine(
                array_map(static fn (string $method): string => $controller.$method, array_keys($methods)),
                array_map($returns, $methods),
            ),
            traceOverrides: array_combine(
                array_map(static fn (string $method): string => $controller.$method, array_keys($walks)),
                array_values($walks),
            ),
        );
    };
});

/**
 * @return array<string, array<string, string>> link name → its parameters
 */
function dataLinksOf(array $document, string $path, string $method, string $status): array
{
    return array_map(
        static fn (array $link): array => $link['parameters'],
        $document['paths'][$path][$method]['responses'][$status]['links'] ?? [],
    );
}

it('publishes the links its Data bodies prove, warm as cold', function (): void {
    $warm = assertWarmEqualsCold($this->routes, $this->routes, $this->engine);

    assertGolden('data-response-links.uir.json', (new UirEmitter)->emit($warm->document));
});

it('links a Data body built from a model to every route that binds the model on a column it copies', function (string $path, string $method, string $status, array $links): void {
    $document = emittedArray(localityBuild($this->routes, $this->engine));

    expect(dataLinksOf($document, $path, $method, $status))->toBe($links);
})->with([
    // `from($van)` copies the key into `id` and the plate into `plate`, so each route is filled with the
    // very value it looks up. `colour` comes through an accessor: no route is matched on it anyway.
    'from() a model' => ['/api/vans', 'post', '201', [
        'get.api.plated-vans.@van' => ['van' => '$response.body#/plate'],
        'get.api.vans.@van' => ['van' => '$response.body#/id'],
    ]],
    // The constructor, handed `$van->id` and `$van->plate`: the van read back links to its other route.
    'the constructor, by name' => ['/api/vans/{van}', 'get', '200', [
        'get.api.plated-vans.@van' => ['van' => '$response.body#/plate'],
    ]],
    'from() an array' => ['/api/plated-vans/{van}', 'get', '200', [
        'get.api.vans.@van' => ['van' => '$response.body#/id'],
    ]],
    // The plate is read from `plate` and sent as `registration`: the pointer names the member sent.
    'from() a model, under mapped names' => ['/api/fleet', 'post', '201', [
        'get.api.plated-vans.@van' => ['van' => '$response.body#/registration'],
        'get.api.vans.@van' => ['van' => '$response.body#/id'],
    ]],
    'the constructor by position, the key read through getKey()' => ['/api/fleet/in-order', 'post', '201', [
        'get.api.plated-vans.@van' => ['van' => '$response.body#/registration'],
        'get.api.vans.@van' => ['van' => '$response.body#/id'],
    ]],
    // The action takes the envelope off after building it, so where the body documents the key is not
    // where the response sends it.
    'an object the action unwraps' => ['/api/unwrapped-vans', 'post', '201', []],
    // A cast, a transformer, a nullable or lazy property: none of them sends the column as stored.
    'properties that are not plain copies' => ['/api/painted-vans', 'post', '201', []],
    // The action sets the plate again after `from()` copied it, so the body sends what it set.
    'a writable property the action sets again' => ['/api/retitled-vans', 'post', '201', []],
    // The class's own `from()` decides what the object is built from, whatever it was handed.
    'a from() the class writes itself' => ['/api/demonstration-vans', 'post', '201', []],
    // A helper typed on the base class adds a `plate` of its own over the copied one.
    'an object a helper changes through the base class' => ['/api/described-vans', 'post', '201', []],
]);

it('points into the envelope a Data body is wrapped in', function (): void {
    config(['data.wrap' => 'data']);

    $document = emittedArray(localityBuild($this->routes, $this->engine));

    expect($document['paths']['/api/vans']['post']['responses']['201']['content']['application/json']['schema']['required'])->toBe(['data'])
        ->and(dataLinksOf($document, '/api/vans', 'post', '201'))->toBe([
            'get.api.plated-vans.@van' => ['van' => '$response.body#/data/plate'],
            'get.api.vans.@van' => ['van' => '$response.body#/data/id'],
        ]);
});

/**
 * An older version sends the plate as `number_plate`, so a pointer at `/plate` reads nothing it sends.
 * The key is untouched by the change and is still linked.
 */
it('links on no member of a Data body a later version renamed', function (): void {
    setDocuments(['default' => [
        ...documentSettings(),
        'info' => ['title' => 'Vans', 'version' => '2026-06-01'],
        'api_version' => ['changes' => [dirname(__DIR__).'/Fixtures/ResponseLinks/Versions/PlateRenamed']],
    ]]);

    $document = emittedArray(localityBuild($this->routes, $this->engine));

    expect(array_keys($document['components']['schemas']['VanData']['properties']))->toContain('number_plate')
        ->and(dataLinksOf($document, '/api/vans', 'post', '201'))->toBe([
            'get.api.vans.@van' => ['van' => '$response.body#/id'],
        ]);
});

/**
 * How `from()` reads a model is the application's `data.normalizers`, which no route file records. A
 * warm build after it changes must write what a cold one does: here, a model read as its serialised
 * array first, which leaves `from($van)` nothing it can trust.
 */
it('rebuilds a Data body\'s links when the normalizers reading a model change', function (): void {
    $dir = fragmentCacheDir('data-links');

    try {
        $cold = emittedArray(localityBuild($this->routes, $this->engine));
        expect(glob($dir.'/*.json') ?: [])->not->toBeEmpty();

        config(['data.normalizers' => ['Spatie\\LaravelData\\Normalizers\\ArrayableNormalizer', 'Spatie\\LaravelData\\Normalizers\\ModelNormalizer']]);
        $warm = emittedArray(localityBuild($this->routes, $this->engine));

        expect(dataLinksOf($cold, '/api/vans', 'post', '201'))->toHaveKey('get.api.vans.@van')
            ->and(dataLinksOf($warm, '/api/vans', 'post', '201'))->toBe([])
            // Built by the constructor, untouched by the normalizers.
            ->and(dataLinksOf($warm, '/api/vans/{van}', 'get', '200'))->toHaveKey('get.api.plated-vans.@van');
    } finally {
        removeFragmentCacheDir($dir);
    }
});

/**
 * laravel-data takes a normalizer as an object as readily as by its class name. One the application wrote,
 * put before the model's, may rewrite what `from()` reads; laravel-data's own handed over as an object does
 * not. A warm build after one replaces the other must write what a cold one does.
 */
it('reads a normalizer handed over as an object as the class it is', function (): void {
    $dir = fragmentCacheDir('data-links-objects');

    try {
        config(['data.normalizers' => [new ObjectNormalizer, ModelNormalizer::class]]);
        $cold = emittedArray(localityBuild($this->routes, $this->engine));

        config(['data.normalizers' => [new PlateNormalizer, ModelNormalizer::class]]);
        $warm = emittedArray(localityBuild($this->routes, $this->engine));

        expect(dataLinksOf($cold, '/api/vans', 'post', '201'))->toHaveKey('get.api.plated-vans.@van')
            ->and(dataLinksOf($warm, '/api/vans', 'post', '201'))->toBe([]);
    } finally {
        removeFragmentCacheDir($dir);
    }
});
