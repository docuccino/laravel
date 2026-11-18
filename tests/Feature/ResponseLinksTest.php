<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\ExtensionSorter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Links\ResponseLinkAssembly;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\BranchingCrateResource;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Crate;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\CrateController;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\KeyedCrateResource;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\LabelController;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\MisdeclaredLinkController;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Pallet;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\PalletResource;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Pamphlet;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\PamphletController;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Parcel;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\ParcelController;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\ParcelResource;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Reel;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\ReelController;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Scan;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\ScanController;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\ScanResource;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Token;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\TokenController;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Docuccino\Laravel\Versioning\ApiVersionTransformer;
use Docuccino\Laravel\Workflows\WorkflowAssembly;
use Illuminate\Routing\Router;

/**
 * Response links the code proves: a response that sends back a model's column, linked to the operations
 * whose path parameters are matched on that column. Each one is a call a client can make with what the
 * response handed it, so the rows state which proof makes it true, and the refusals which one is missing.
 */
beforeEach(function (): void {
    $this->routes = static function (Router $router): void {
        $router->bind('consignment', static fn (string $value): Parcel => new Parcel);

        $router->post('api/parcels', [ParcelController::class, 'store']);
        $router->get('api/parcels/{parcel}', [ParcelController::class, 'show']);
        $router->patch('api/parcels/{parcel}', [ParcelController::class, 'update']);
        $router->delete('api/parcels/{parcel}', [ParcelController::class, 'destroy']);
        $router->get('api/tracked-parcels/{parcel:tracking}', [ParcelController::class, 'track']);
        $router->post('api/raw-parcels', [ParcelController::class, 'storeRaw']);
        $router->get('api/consignments/{consignment}', [ParcelController::class, 'consigned']);
        $router->model('shipment', Parcel::class);
        $router->get('api/shipments/{shipment}', [ParcelController::class, 'shipped']);
        $router->post('api/pallets', [ParcelController::class, 'storePallet']);
        $router->get('api/pallets/{pallet}', [ParcelController::class, 'showPallet']);
        $router->post('api/parcels/{parcel}/scans', [ScanController::class, 'store']);
        $router->get('api/parcels/{parcel}/scans/{scan}', [ScanController::class, 'show'])->scopeBindings();
        $router->patch('api/parcels/{parcel}/scans/{scan}', [ScanController::class, 'update'])->scopeBindings();
        $router->get('api/parcels/{parcel}/latest-scan', [ScanController::class, 'latest']);
        $router->get('api/scans/{scan}', [ScanController::class, 'find']);
        $router->post('api/labels', [LabelController::class, 'store']);
    };

    $this->engine = static function (array $more = []): TypeEngine {
        $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))]);
        $controller = ParcelController::class.'::';

        return WorkbenchEngine::make(
            classOverrides: [
                Pallet::class => new ClassMetadata(Pallet::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('tag', ScalarT::string()),
                ]),
                Scan::class => new ClassMetadata(Scan::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('parcel_id', ScalarT::int()),
                    new PropertyMetadata('place', ScalarT::string()),
                ]),
                Parcel::class => new ClassMetadata(Parcel::class, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('tracking', ScalarT::string()),
                    new PropertyMetadata('label', ScalarT::string()),
                ]),
            ],
            analysisOverrides: [
                ...$more,
                $controller.'store' => $returns(ParcelResource::class),
                $controller.'show' => $returns(ParcelResource::class),
                $controller.'update' => $returns(ParcelResource::class),
                $controller.'track' => $returns(ParcelResource::class),
                $controller.'storeRaw' => $returns(Parcel::class),
                $controller.'consigned' => $returns(ParcelResource::class),
                $controller.'shipped' => $returns(ParcelResource::class),
                $controller.'storePallet' => $returns(PalletResource::class),
                $controller.'showPallet' => $returns(PalletResource::class),
                ScanController::class.'::store' => $returns(ScanResource::class),
                LabelController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('parcel', ScalarT::int()),
                    new ArrayShapeField('printer', ScalarT::string()),
                ]), new SourceLocation(''))]),
                ScanController::class.'::show' => $returns(ScanResource::class),
                ScanController::class.'::update' => $returns(ScanResource::class),
                ScanController::class.'::latest' => $returns(ScanResource::class),
                ScanController::class.'::find' => $returns(ScanResource::class),
                ScanResource::class.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('id', ScalarT::int()),
                    new ArrayShapeField('parcel_id', ScalarT::int()),
                    new ArrayShapeField('place', ScalarT::string()),
                ]), new SourceLocation(''))]),
                PalletResource::class.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('id', ScalarT::int()),
                    new ArrayShapeField('tag', ScalarT::string()),
                ]), new SourceLocation(''))]),
                ParcelResource::class.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('id', ScalarT::int()),
                    new ArrayShapeField('tracking', ScalarT::string()),
                    new ArrayShapeField('label', ScalarT::string()),
                ]), new SourceLocation(''))]),
            ],
        );
    };
});

it('publishes the links its routes prove, warm as cold', function (): void {
    $warm = assertWarmEqualsCold($this->routes, $this->routes, $this->engine);

    assertGolden('response-links.uir.json', (new UirEmitter)->emit($warm->document));
});

it('links a create endpoint to every route that binds the model it gives back', function (): void {
    $document = emittedArray(localityBuild($this->routes, $this->engine));
    $links = $document['paths']['/api/parcels']['post']['responses']['200']['links'];

    // Each segment is matched on a column the resource sends as it is stored: the key under `id`, the
    // tracking code under `tracking`. So the value handed back is the value each route looks up.
    expect(array_map(static fn (array $link): array => $link['parameters'], $links))->toBe([
        'delete.api.parcels.@parcel' => ['parcel' => '$response.body#/data/id'],
        'get.api.parcels.@parcel' => ['parcel' => '$response.body#/data/id'],
        // A route under the parcel takes nothing else, so the parcel's key is all it needs.
        'get.api.parcels.@parcel.latest-scan' => ['parcel' => '$response.body#/data/id'],
        // `Route::model('shipment', Parcel::class)` resolves on the parcel's route key, so the key fills it.
        'get.api.shipments.@shipment' => ['shipment' => '$response.body#/data/id'],
        'get.api.tracked-parcels.@parcel' => ['parcel' => '$response.body#/data/tracking'],
        'patch.api.parcels.@parcel' => ['parcel' => '$response.body#/data/id'],
        'post.api.parcels.@parcel.scans' => ['parcel' => '$response.body#/data/id'],
    ])
        ->and($links['get.api.parcels.@parcel']['operationId'])->toBe('get.api.parcels.@parcel')
        ->and($links['get.api.parcels.@parcel']['x-docuccino']['provenance'])->toBe([['producer' => 'inference', 'layer' => 'inference']]);
});

it('links a resource to its own other routes', function (): void {
    $document = emittedArray(localityBuild($this->routes, $this->engine));
    $links = $document['paths']['/api/parcels/{parcel}']['get']['responses']['200']['links'];

    // The parcel read back carries its own key, so every other route on that parcel is one call away —
    // and the route that read it is not a place to go.
    expect(array_keys($links))->toBe([
        'delete.api.parcels.@parcel',
        'get.api.parcels.@parcel.latest-scan',
        'get.api.shipments.@shipment',
        'get.api.tracked-parcels.@parcel',
        'patch.api.parcels.@parcel',
        'post.api.parcels.@parcel.scans',
    ])
        ->and($links['patch.api.parcels.@parcel']['parameters'])->toBe(['parcel' => '$response.body#/data/id']);
});

/**
 * An older version's document is a contract of its own: a client reading it can call only what that
 * version serves and read only what it sends. So a link in it names an operation that version publishes
 * and reads a member under the name that version gives it — the join reads the versioned document.
 */
it('links only what the version it documents publishes', function (string $changes, Closure $applied, string $gone, string $absent): void {
    setDocuments(['default' => [
        ...documentSettings(),
        'info' => ['title' => 'Parcels', 'version' => '2026-06-01'],
        'api_version' => ['changes' => [dirname(__DIR__).'/Fixtures/ResponseLinks/Versions/'.$changes]],
    ]]);

    $document = emittedArray(localityBuild($this->routes, $this->engine));
    $links = $document['paths']['/api/parcels']['post']['responses']['200']['links'] ?? [];

    // The change took effect, and what it left alone is still linked: the row is not passing on a build
    // that versioned nothing, or linked nothing.
    expect($applied($document))->toBeTrue()
        ->and($document['paths']['/api/pallets']['post']['responses']['200']['links'])->toHaveKey('get.api.pallets.@pallet')
        ->and($links)->not->toHaveKey($gone)
        ->and(json_encode($document, JSON_UNESCAPED_SLASHES))->not->toContain($absent);
})->with([
    // The read route arrived later: the older document does not publish it, so nothing may lead there.
    'an operation a later version added' => [
        'ReadBackAdded',
        static fn (array $document): bool => ! isset($document['paths']['/api/parcels/{parcel}']['get'])
            && isset($document['paths']['/api/parcels']['post']['responses']['200']['links']['patch.api.parcels.@parcel']),
        'get.api.parcels.@parcel',
        '"operationId":"get.api.parcels.@parcel"',
    ],
    // The older resource sends `tracking_code`, so a pointer at `/data/tracking` reads nothing it sends.
    // The bare model is not the renamed resource, so its own `/tracking` link stands.
    'a member a later version renamed' => [
        'TrackingRenamed',
        static fn (array $document): bool => str_contains((string) json_encode($document['components']['schemas']['ParcelResource']), '"tracking_code"')
            && isset($document['paths']['/api/raw-parcels']['post']['responses']['200']['links']['get.api.tracked-parcels.@parcel']),
        'get.api.tracked-parcels.@parcel',
        'body#/data/tracking"',
    ],
    // The older resource sends the key as `internal_id` and the tracking code under `id`: `/data/id` is
    // documented there, and holds a value no `{parcel}` route matches.
    'a member a later version moved another field onto' => [
        'KeyRepointed',
        static fn (array $document): bool => array_keys($document['components']['schemas']['ParcelResource']['properties']) === ['id', 'internal_id', 'label'],
        'get.api.parcels.@parcel',
        '"parcel":"$response.body#/data/id"',
    ],
]);

/**
 * Versioning and the joins over its output all run late, and a tie at one priority goes to the class
 * name. So each join owes versioning a hard edge: without one, renaming a class would decide whether an
 * older version's document names operations and members that version never had.
 */
it('joins over the versioned document whatever the joins are called', function (string $join): void {
    $order = (new ReflectionClass($join))->getAttributes(ExtensionOrder::class)[0]->newInstance();
    $sorted = (new ExtensionSorter)->sort([app($join), app(ApiVersionTransformer::class)]);

    expect($order->after)->toContain(ApiVersionTransformer::class)
        ->and(array_map(static fn (object $extension): string => $extension::class, $sorted))->toBe([ApiVersionTransformer::class, $join]);
})->with([
    'response links' => [ResponseLinkAssembly::class],
    'workflows' => [WorkflowAssembly::class],
]);

/**
 * A scoped child resolves only within its parent, so a scan id from any other parcel 404s. The parent a
 * link passes on is the one in the request, so the scan it pairs with has to be one that parcel owns:
 * proved for a scan made under it, or read back from under it, and for nothing else.
 */
it('fills a parent segment from the request, and a scoped child only from its own collection', function (): void {
    $paths = emittedArray(localityBuild($this->routes, $this->engine))['paths'];
    $linked = static fn (string $path, string $method): array => array_map(
        static fn (array $link): array => $link['parameters'],
        $paths[$path][$method]['responses']['200']['links'] ?? [],
    );

    $made = $linked('/api/parcels/{parcel}/scans', 'post');
    $read = $linked('/api/parcels/{parcel}/scans/{scan}', 'get');
    $latest = $linked('/api/parcels/{parcel}/latest-scan', 'get');

    expect($made['get.api.parcels.@parcel.scans.@scan'])->toBe(['parcel' => '$request.path.parcel', 'scan' => '$response.body#/data/id'])
        ->and($made['get.api.scans.@scan'])->toBe(['scan' => '$response.body#/data/id'])
        ->and($read)->toBe([
            'get.api.scans.@scan' => ['scan' => '$response.body#/data/id'],
            'patch.api.parcels.@parcel.scans.@scan' => ['parcel' => '$request.path.parcel', 'scan' => '$response.body#/data/id'],
        ])
        // Nothing in the code says the latest scan is this parcel's, so only the unscoped lookup takes it.
        ->and($latest)->toBe(['get.api.scans.@scan' => ['scan' => '$response.body#/data/id']])
        // `parcel_id` is a scan's column that happens to be named for a parcel: not linked on.
        ->and(json_encode($paths))->not->toContain('parcel_id"');
});

it('links on the route key a model names for itself', function (): void {
    $document = emittedArray(localityBuild($this->routes, $this->engine));

    // `Pallet::getRouteKeyName()` returns `'tag'`, so the tag is what `{pallet}` is looked up by — the
    // key would be a value the route never matches.
    expect($document['paths']['/api/pallets']['post']['responses']['200']['links'])
        ->toHaveKey('get.api.pallets.@pallet')
        ->and($document['paths']['/api/pallets']['post']['responses']['200']['links']['get.api.pallets.@pallet']['parameters'])
        ->toBe(['pallet' => '$response.body#/data/tag']);
});

it('reads a model returned bare as sending its columns under their own names', function (): void {
    $document = emittedArray(localityBuild($this->routes, $this->engine));
    $links = $document['paths']['/api/raw-parcels']['post']['responses']['200']['links'];

    expect($links['get.api.parcels.@parcel']['parameters'])->toBe(['parcel' => '$response.body#/id'])
        ->and($links['get.api.tracked-parcels.@parcel']['parameters'])->toBe(['parcel' => '$response.body#/tracking']);
});

it('writes no link to a segment whose binding it could not read', function (): void {
    $document = emittedArray(localityBuild($this->routes, $this->engine));
    $targets = [];
    foreach ($document['paths'] as $item) {
        foreach ($item as $operation) {
            foreach ($operation['responses'] as $response) {
                foreach ($response['links'] ?? [] as $link) {
                    $targets[$link['operationId']] = true;
                }
            }
        }
    }

    // The application's own binder decides what `{consignment}` holds, in a closure.
    expect($targets)->not->toHaveKey('get.api.consignments.@consignment')
        // …and the label is rewritten on its way out, so nothing is ever linked on it.
        ->and(json_encode($document['paths'], JSON_UNESCAPED_SLASHES))->not->toContain('body#/data/label');
});

/**
 * A model that resolves its own bindings — a hashid trait decoding the segment — matches on whatever its
 * code says. The key it sends, or a column the route names, is a value the server never looks up by, so
 * following a link on it would 404, or find the wrong record.
 */
it('writes no link to a model that resolves its bindings itself', function (): void {
    $routes = static function (Router $router): void {
        $router->post('api/tokens', [TokenController::class, 'store']);
        $router->get('api/tokens/{token}', [TokenController::class, 'show']);
        $router->get('api/tokens-by-code/{token:code}', [TokenController::class, 'show']);
        $router->post('api/crates', [CrateController::class, 'store']);
        $router->get('api/crates/{crate}', [CrateController::class, 'show']);
    };
    $engine = static function (): TypeEngine {
        $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))]);

        return WorkbenchEngine::make(
            classOverrides: [
                Token::class => new ClassMetadata(Token::class, [new PropertyMetadata('id', ScalarT::int()), new PropertyMetadata('code', ScalarT::string())]),
                Crate::class => new ClassMetadata(Crate::class, [new PropertyMetadata('id', ScalarT::int())]),
            ],
            analysisOverrides: [
                TokenController::class.'::store' => $returns(Token::class),
                TokenController::class.'::show' => $returns(Token::class),
                CrateController::class.'::store' => $returns(Crate::class),
                CrateController::class.'::show' => $returns(Crate::class),
            ],
        );
    };

    $paths = emittedArray(localityBuild($routes, $engine))['paths'];

    // The plain model beside it is linked, so the build is one that links.
    expect($paths['/api/crates']['post']['responses']['200']['links'])->toHaveKey('get.api.crates.@crate')
        ->and($paths['/api/tokens']['post']['responses']['200'])->not->toHaveKey('links')
        ->and($paths['/api/tokens/{token}']['get']['responses']['200'])->not->toHaveKey('links');
});

/**
 * A model that overrides its binding only to narrow which records resolve still looks the segment up on
 * its route key, with the value as it arrived, so the key a body sends is the value the route takes: it
 * is linked as any model is. Its narrowing is a global scope's on a default binding — a record outside it
 * answers 404 either way — and the link says only which value the parameter takes.
 */
it('links a model that only narrows its own lookup as any model', function (): void {
    $routes = static function (Router $router): void {
        $router->post('api/pamphlets', [PamphletController::class, 'store']);
        $router->get('api/pamphlets/{pamphlet}', [PamphletController::class, 'show']);
    };
    $engine = static function (): TypeEngine {
        $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))]);

        return WorkbenchEngine::make(
            classOverrides: [
                Pamphlet::class => new ClassMetadata(Pamphlet::class, [new PropertyMetadata('id', ScalarT::int()), new PropertyMetadata('published', ScalarT::bool())]),
            ],
            analysisOverrides: [
                PamphletController::class.'::store' => $returns(Pamphlet::class),
                PamphletController::class.'::show' => $returns(Pamphlet::class),
            ],
        );
    };

    $paths = emittedArray(localityBuild($routes, $engine))['paths'];

    expect($paths['/api/pamphlets']['post']['responses']['200']['links']['get.api.pamphlets.@pamphlet']['parameters'] ?? null)
        ->toBe(['pamphlet' => '$response.body#/id']);
});

/**
 * A model returned as it is sends its columns under their own names only while the framework writes its
 * serialised form. One that writes its own may send anything under a column's name — here its uuid under
 * `id` — so no member is known to hold the value a route looks the model up by.
 */
it('writes no link from a model that writes its own serialised form', function (): void {
    $routes = static function (Router $router): void {
        $router->post('api/reels', [ReelController::class, 'store']);
        $router->get('api/reels/{reel}', [ReelController::class, 'show']);
        $router->post('api/crates', [CrateController::class, 'store']);
        $router->get('api/crates/{crate}', [CrateController::class, 'show']);
    };
    $engine = static function (): TypeEngine {
        $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))]);

        return WorkbenchEngine::make(
            classOverrides: [
                Reel::class => new ClassMetadata(Reel::class, [new PropertyMetadata('id', ScalarT::int()), new PropertyMetadata('uuid', ScalarT::string())]),
                Crate::class => new ClassMetadata(Crate::class, [new PropertyMetadata('id', ScalarT::int())]),
            ],
            analysisOverrides: [
                ReelController::class.'::store' => $returns(Reel::class),
                ReelController::class.'::show' => $returns(Reel::class),
                CrateController::class.'::store' => $returns(Crate::class),
                CrateController::class.'::show' => $returns(Crate::class),
            ],
        );
    };

    $paths = emittedArray(localityBuild($routes, $engine))['paths'];

    expect($paths['/api/crates']['post']['responses']['200']['links'])->toHaveKey('get.api.crates.@crate')
        ->and($paths['/api/reels']['post']['responses']['200'])->not->toHaveKey('links');
});

/**
 * A route looks a record up by the value stored in the column, so only a member that reaches the wire as
 * stored can fill its segment. Each refusal below is a member whose value the server rewrote on the way
 * out: passing it back would look up something else, or nothing.
 */
it('links on a column only where the body sends it as it is stored', function (): void {
    $routes = static function (Router $router): void {
        $router->post('api/crates', [CrateController::class, 'store']);
        $router->post('api/keyed-crates', [CrateController::class, 'storeKeyed']);
        $router->post('api/branching-crates', [CrateController::class, 'storeBranching']);
        $router->get('api/crates/{crate}', [CrateController::class, 'show']);
        foreach (['code', 'label', 'secret', 'badge', 'created_at'] as $column) {
            $router->get('api/crates-by-'.$column.'/{crate:'.$column.'}', [CrateController::class, 'show']);
        }
    };
    $engine = static function (): TypeEngine {
        $returns = static fn (string $fqcn): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($fqcn), new SourceLocation(''))]);
        $shape = static fn (array $fields): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT($fields), new SourceLocation(''))]);
        $controller = CrateController::class.'::';

        return WorkbenchEngine::make(
            classOverrides: [Crate::class => new ClassMetadata(Crate::class, [
                new PropertyMetadata('id', ScalarT::int()),
                new PropertyMetadata('code', ScalarT::int()),
                new PropertyMetadata('label', ScalarT::string()),
                new PropertyMetadata('secret', ScalarT::string()),
            ])],
            analysisOverrides: [
                $controller.'store' => $returns(Crate::class),
                $controller.'show' => $returns(Crate::class),
                $controller.'storeKeyed' => $returns(KeyedCrateResource::class),
                $controller.'storeBranching' => $returns(BranchingCrateResource::class),
                KeyedCrateResource::class.'::toArray' => $shape([new ArrayShapeField('ref', ScalarT::int()), new ArrayShapeField('maybe', ScalarT::int())]),
                BranchingCrateResource::class.'::toArray' => $shape([new ArrayShapeField('id', ScalarT::int()), new ArrayShapeField('code', ScalarT::int())]),
            ],
        );
    };

    $paths = emittedArray(localityBuild($routes, $engine))['paths'];
    $linked = static fn (string $path): array => array_map(
        static fn (array $link): array => $link['parameters'],
        $paths[$path]['post']['responses']['200']['links'] ?? [],
    );

    // The key, and a code cast to `integer`, which writes the number it stores. Not the label an accessor
    // capitalises, the secret an encrypting cast hides, the badge appended from no column, or the
    // timestamp the framework writes in its own date format.
    expect($linked('/api/crates'))->toBe([
        'get.api.crates-by-code.@crate' => ['crate' => '$response.body#/code'],
        'get.api.crates.@crate' => ['crate' => '$response.body#/id'],
    ])
        // `getRouteKey()` is the route key; a member sent only `when()` something holds is not always there.
        ->and($linked('/api/keyed-crates'))->toBe(['get.api.crates.@crate' => ['crate' => '$response.body#/data/ref']])
        // Two returns agree on the key and not on the code, and a closure's return is not one of them.
        ->and($linked('/api/branching-crates'))->toBe(['get.api.crates.@crate' => ['crate' => '$response.body#/data/id']]);
});

it('writes a declared link with where it was declared', function (): void {
    $document = emittedArray(localityBuild($this->routes, $this->engine));
    $link = $document['paths']['/api/labels']['post']['responses']['200']['links']['LabelledParcel'];

    // Nothing in the code ties `parcel` to a parcel's key — the label's author does, and says so.
    expect($link['operationId'])->toBe('get.api.parcels.@parcel')
        ->and($link['parameters'])->toBe(['parcel' => '$response.body#/parcel'])
        ->and($link['description'])->toBe('The parcel the label is for.')
        ->and($link['x-docuccino']['provenance'][0])->toMatchArray(['producer' => 'attribute', 'layer' => 'attribute'])
        ->and($link['x-docuccino']['provenance'][0]['source']['symbol'])->toBe(LabelController::class.'::store')
        ->and($link['x-docuccino']['provenance'][0]['source']['file'])->toBe('tests/Fixtures/ResponseLinks/LabelController.php');
});

/**
 * A declared link is a promise the document makes to a client, so one the document cannot keep is left
 * out and reported to its author, naming what to fix — never published to send a client somewhere that
 * is not there.
 */
it('reports a declared link the document cannot honour, and leaves it out', function (): void {
    $routes = function (Router $router): void {
        ($this->routes)($router);
        foreach (['unknownOperation', 'unknownStatus', 'undeclaredParameter', 'undocumentedPointer', 'unreadable', 'honoured'] as $action) {
            $router->post('api/misdeclared/'.$action, [MisdeclaredLinkController::class, $action]);
        }
    };
    $engine = function (): TypeEngine {
        $parcel = new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('parcel', ScalarT::int())]), new SourceLocation(''))]);
        $more = [];
        foreach (['unknownOperation', 'unknownStatus', 'undeclaredParameter', 'undocumentedPointer', 'unreadable', 'honoured'] as $action) {
            $more[MisdeclaredLinkController::class.'::'.$action] = $parcel;
        }

        return ($this->engine)($more);
    };

    $result = localityBuild($routes, $engine);
    $paths = emittedArray($result)['paths'];
    $reported = [];
    foreach ($result->diagnostics as $diagnostic) {
        if (str_starts_with($diagnostic->code, 'response-link.')) {
            $reported[(string) $diagnostic->routeSignature] = $diagnostic->code;
        }
    }
    ksort($reported);

    expect($reported)->toBe([
        'POST /api/misdeclared/undeclaredParameter' => 'response-link.parameter-undeclared',
        'POST /api/misdeclared/undocumentedPointer' => 'response-link.pointer-undocumented',
        'POST /api/misdeclared/unknownOperation' => 'response-link.unknown-operation',
        'POST /api/misdeclared/unknownStatus' => 'response-link.unknown-status',
        'POST /api/misdeclared/unreadable' => 'response-link.unreadable',
    ]);

    foreach (['unknownOperation', 'unknownStatus', 'undeclaredParameter', 'undocumentedPointer', 'unreadable'] as $action) {
        expect($paths['/api/misdeclared/'.$action]['post']['responses']['200'])->not->toHaveKey('links');
    }

    // A parameter qualified by its location is how OpenAPI tells two of one name apart.
    expect($paths['/api/misdeclared/honoured']['post']['responses']['200']['links']['get.api.parcels.@parcel']['parameters'])
        ->toBe(['path.parcel' => '$response.body#/parcel']);
});

/**
 * Which model a resource wraps is read where the action builds it, typed by the analyser — the half no
 * stub can stand in for. Anything but exactly one model class is no answer, because a link read off the
 * wrong model's columns sends a client a value no route matches.
 */
it('reads the model a resource wraps from where the action builds it, on the real engine', function (string $method, ?string $model): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $trace = FixtureRunner::traceResourceModels(
        'app/Http/Controllers/LinkedUserController.php',
        'App\\Http\\Controllers\\LinkedUserController',
        $method,
        'App\\Http\\Resources\\LinkedUserResource',
    );

    expect($trace['model'])->toBe($model);
})->with([
    'new Resource(Model::create())' => ['made', 'App\\Models\\User'],
    'Resource::make($bound)' => ['shown', 'App\\Models\\User'],
    'built in a helper the action calls' => ['helped', 'App\\Models\\User'],
    'built around a find() that may be null' => ['maybe', null],
])->group('fixture');

/**
 * A `@mixin` is written for the IDE and says nothing about what one action wraps. So it stands in only
 * where the walk saw no construction at all; a construction around either of two models is an answer —
 * none — and a link read off the mixin's columns there would send another model's key.
 */
it('reads a resource\'s @mixin only where no construction of it shows, on the real engine', function (string $method, ?string $model): void {
    ensureFixtureAvailable(FixtureRunner::available());

    $trace = FixtureRunner::traceResourceModels(
        'app/Http/Controllers/LinkedUserController.php',
        'App\\Http\\Controllers\\LinkedUserController',
        $method,
        'App\\Http\\Resources\\MixinUserResource',
    );

    expect($trace['wrapped'])->toBe($model);
})->with([
    'built around the model it names' => ['agreed', 'App\\Models\\User'],
    'built around either of two models' => ['either', null],
    'handed out by the model, no construction in sight' => ['handedOut', 'App\\Models\\User'],
])->group('fixture');
