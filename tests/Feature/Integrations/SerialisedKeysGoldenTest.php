<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Inference\PhpStan\Metadata\ClassMetadataFactory;
use Docuccino\Laravel\Tests\Fixtures\SerialisedKeys\WidgetBadge;
use Docuccino\Laravel\Tests\Fixtures\SerialisedKeys\WidgetBadgeResource;
use Docuccino\Laravel\Tests\Fixtures\SerialisedKeys\WidgetCaption;
use Docuccino\Laravel\Tests\Fixtures\SerialisedKeys\WidgetController;
use Docuccino\Laravel\Tests\Fixtures\SerialisedKeys\WidgetStock;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * Plain objects on the wire, locked in emitted bytes. A response's `required` says whether the KEY is
 * sent, and `json_encode` writes every initialised public property, `null` included — so a nullable key
 * a plain object always initialises is required, and nothing else in the corpus is a plain object with
 * a nullable property that is sent, nor one accepted as a request body. The class metadata is the engine's own reflection read; the route
 * and resource return types are scripted, as everywhere in this suite.
 */
beforeEach(function (): void {
    $this->engine = static function (): TypeEngine {
        $location = new SourceLocation('');
        $factory = new ClassMetadataFactory;
        $classes = [];
        foreach ([WidgetBadge::class, WidgetStock::class, WidgetCaption::class] as $class) {
            $classes[$class] = $factory->forClass(new ClassRef($class));
        }

        return WorkbenchEngine::make(classOverrides: $classes, analysisOverrides: [
            WidgetController::class.'::badges' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(WidgetBadgeResource::class), $location)]),
            WidgetBadgeResource::class.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                new ArrayShapeField('badges', new ListT(new ClassT(WidgetBadge::class))),
            ]), $location)]),
            WidgetController::class.'::stock' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(WidgetStock::class), $location)]),
            WidgetController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(WidgetStock::class), $location)]),
            WidgetController::class.'::caption' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(WidgetCaption::class), $location)]),
        ]);
    };

    $this->routes = static function (Router $router): void {
        $router->get('api/zz-widget-badges', [WidgetController::class, 'badges']);
        $router->get('api/zz-widget-stock', [WidgetController::class, 'stock']);
        $router->get('api/zz-widget-caption', [WidgetController::class, 'caption']);
        $router->post('api/zz-widget-stock', [WidgetController::class, 'store']);
    };
});

afterEach(fn () => removeFragmentCacheDirs('serialised-keys'));

it('emits plain objects byte-identical to the committed golden', function (): void {
    $result = localityBuild($this->routes, $this->engine);

    assertGolden('serialised-keys.uir.json', (new UirEmitter)->emit($result->document));

    $schemas = emittedArray($result)['components']['schemas'];

    expect($schemas['WidgetBadge']['properties']['icon_url'])->toBe(['type' => ['string', 'null']])
        ->and($schemas['WidgetBadge']['required'])->toBe(['id', 'label', 'pinned', 'icon_url']);
});

/*
 * The contract, stated from the wire rather than from the mapper: each object is built with every
 * nullable value null and every optional one left out, so the body the application really sends is the
 * sparsest it can be — and the keys a document calls required must be exactly the keys that body still
 * carries. Fewer is a client told to guard a key it always gets; more is a working response called invalid.
 */
it('requires exactly the keys the sparsest body really carries', function (string $path, string $component, string $key): void {
    $schemas = emittedArray(localityBuild($this->routes, $this->engine))['components']['schemas'];

    $body = $this->getJson($path)->assertOk()->json();
    $sent = $key === '' ? $body : $body['data'][$key][0];

    $required = $schemas[$component]['required'];
    sort($required);
    $keys = array_keys($sent);
    sort($keys);

    expect($required)->toBe($keys);
})->with([
    'promoted, nested in a resource' => ['api/zz-widget-badges', 'WidgetBadge', 'badges'],
    'defaulted, untyped and promoted beside one never assigned' => ['api/zz-widget-stock', 'WidgetStock', ''],
    'a class stating its own JSON form' => ['api/zz-widget-caption', 'WidgetCaption', ''],
]);

/*
 * The request half, from the contract: a key a client may leave out is optional, and the class says
 * which by what its constructor fills in. `new WidgetStock(1)` builds, so every key but `id` — the
 * non-nullable, defaulted `quantity` included — is one a valid request omits. The request shape is the
 * class's own component, so the response shape beside it keeps every key the server always writes.
 */
it('requires on a request body only what no default fills in', function (): void {
    expect(new WidgetStock(1))->toBeInstanceOf(WidgetStock::class);

    $document = emittedArray(localityBuild($this->routes, $this->engine));
    $body = $document['paths']['/api/zz-widget-stock']['post']['requestBody']['content']['application/json']['schema'];
    $schemas = $document['components']['schemas'];

    expect($body['properties']['stock'])->toBe(['$ref' => '#/components/schemas/WidgetStockRequest'])
        ->and($schemas['WidgetStockRequest']['required'])->toBe(['id'])
        ->and($schemas['WidgetStock']['required'])->toBe(['reorder_level', 'legacy', 'id', 'colour', 'quantity']);
});
