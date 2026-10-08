<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\InlineNoticeController;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\ShipmentController;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\StoreDialledNoticeRequest;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\StoreMixedShipmentRequest;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\StoreNoticeRequest;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\StoreRoutedNoticeRequest;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\StoreShipmentRequest;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\StoreTrackedShipmentRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/*
 * A nullable object whose members are switched on and off by one of them, the way a FormRequest writes a
 * tagged union. Laravel keeps a fixed set of members per tag value, so the server accepts exactly one shape
 * per value — the document says so as one component per value, discriminated by the tag, rather than one
 * object with the partition in prose. The one golden before this whose rules could be read that way is
 * `excluded-fields`, which moves with it.
 */
$engine = static fn (): TypeEngine => WorkbenchEngine::make(traceOverrides: array_merge(...array_map(
    static fn (string $request): array => [$request.'::rules' => TraceScript::forMethod(
        (string) (new ReflectionClass($request))->getFileName(),
        $request,
        'rules',
    )],
    [
        StoreShipmentRequest::class,
        StoreMixedShipmentRequest::class,
        StoreNoticeRequest::class,
        StoreTrackedShipmentRequest::class,
        StoreRoutedNoticeRequest::class,
        StoreDialledNoticeRequest::class,
    ],
)));

$notices = static function (Router $router): void {
    $router->post('api/zz-notices', [ShipmentController::class, 'notify']);
};

$routes = static function (Router $router): void {
    $router->post('api/zz-shipments', [ShipmentController::class, 'store']);
};

it('publishes an object whose members one tag selects as one component per tag value', function () use ($engine, $routes): void {
    $result = localityBuild($routes, $engine);

    assertGolden('tagged-rules.uir.json', (new UirEmitter)->emit($result->document));

    $schemas = emittedArray($result)['components']['schemas'];
    $delivery = $schemas['StoreShipmentRequest']['properties']['delivery'];

    // Every value the enum accepts is a branch, `digital` included though no exclude rule names it; the
    // empty object is accepted because the tag is only required while the object is sent non-empty, and
    // null because the object is nullable. Neither carries a tag, so both sit beside the union.
    expect($delivery['anyOf'][0]['discriminator'])->toBe([
        'mapping' => [
            'courier' => '#/components/schemas/StoreShipmentRequestDeliveryCourier',
            'digital' => '#/components/schemas/StoreShipmentRequestDeliveryDigital',
            'locker' => '#/components/schemas/StoreShipmentRequestDeliveryLocker',
            'pickup' => '#/components/schemas/StoreShipmentRequestDeliveryPickup',
        ],
        'propertyName' => 'method',
    ])
        ->and(array_slice($delivery['anyOf'], 1))->toBe([['type' => 'object', 'maxProperties' => 0], ['type' => 'null']])
        // The tag keeps the enum it is typed by, pinned to the branch's value.
        ->and($schemas['StoreShipmentRequestDeliveryLocker']['properties']['method'])->toBe(['$ref' => '#/components/schemas/DeliveryMethod', 'const' => 'locker'])
        // `present` requires the key as `required` does; `required_with` on the object is `required` on
        // every branch, since each carries its tag and so is sent non-empty.
        ->and($schemas['StoreShipmentRequestDeliveryLocker']['required'])->toBe(['method', 'reference', 'address', 'slots'])
        ->and($schemas['StoreShipmentRequestDeliveryPickup']['required'])->toBe(['method', 'reference', 'contacts'])
        ->and($schemas['StoreShipmentRequestDeliveryDigital']['required'])->toBe(['method', 'reference'])
        // A member excluded for some values is on the others, with its rules.
        ->and(array_keys($schemas['StoreShipmentRequestDeliveryCourier']['properties']))->toBe(['address', 'instructions', 'method', 'reference'])
        ->and($schemas['StoreShipmentRequestDeliveryPickup']['properties'])->not->toHaveKey('instructions')
        // The rules the prose used to stand in for are what each branch states, so none of it is left.
        ->and($schemas['StoreShipmentRequestDeliveryCourier']['properties']['address'])->toBe(['type' => 'string'])
        ->and($schemas['StoreShipmentRequestDeliveryPickup']['properties']['contacts'])->toMatchArray(['minItems' => 1, 'maxItems' => 20]);
});

it('accepts exactly the bodies the server accepts', function (array $body, bool $accepted) use ($engine, $routes): void {
    // The contract this is for: the published union is neither narrower than the FormRequest (a working
    // request marked invalid) nor wider on anything the tag decides. Laravel itself is the oracle.
    expect(requestRuleVerdicts(StoreShipmentRequest::class, $body, $routes, $engine))->toBe([$accepted, $accepted]);
})->with([
    'courier' => [['delivery' => ['method' => 'courier', 'reference' => 'r-1', 'address' => '1 High St'], 'priority' => true], true],
    'courier with instructions' => [['delivery' => ['method' => 'courier', 'reference' => 'r-1', 'address' => '1 High St', 'instructions' => 'ring twice'], 'priority' => false], true],
    'courier, instructions not a string' => [['delivery' => ['method' => 'courier', 'reference' => 'r-1', 'address' => '1 High St', 'instructions' => 5], 'priority' => true], false],
    'courier without its address' => [['delivery' => ['method' => 'courier', 'reference' => 'r-1'], 'priority' => true], false],
    'courier without the reference every branch requires' => [['delivery' => ['method' => 'courier', 'address' => '1 High St'], 'priority' => true], false],
    'locker, slots present and empty' => [['delivery' => ['method' => 'locker', 'reference' => 'r-1', 'address' => 'Locker 4', 'slots' => []], 'priority' => true], true],
    'locker without its present slots' => [['delivery' => ['method' => 'locker', 'reference' => 'r-1', 'address' => 'Locker 4'], 'priority' => true], false],
    'locker, a slot not an integer' => [['delivery' => ['method' => 'locker', 'reference' => 'r-1', 'address' => 'Locker 4', 'slots' => ['x']], 'priority' => true], false],
    'pickup' => [['delivery' => ['method' => 'pickup', 'reference' => 'r-1', 'contacts' => ['ana']], 'priority' => true], true],
    'pickup, contacts empty' => [['delivery' => ['method' => 'pickup', 'reference' => 'r-1', 'contacts' => []], 'priority' => true], false],
    'pickup, excluded members sent with any value' => [['delivery' => ['method' => 'pickup', 'reference' => 'r-1', 'contacts' => ['ana'], 'instructions' => 5, 'slots' => ['x'], 'address' => 1], 'priority' => true], true],
    'digital, named by no exclude rule' => [['delivery' => ['method' => 'digital', 'reference' => 'r-1'], 'priority' => true], true],
    'a value the enum refuses' => [['delivery' => ['method' => 'drone', 'reference' => 'r-1'], 'priority' => true], false],
    'no tag on a non-empty object' => [['delivery' => ['reference' => 'r-1'], 'priority' => true], false],
    'the empty object' => [['delivery' => new stdClass, 'priority' => true], true],
    'null' => [['delivery' => null, 'priority' => true], true],
    'no delivery key' => [['priority' => true], false],
]);

it('builds the same union warm as cold', function () use ($engine, $routes): void {
    $warm = assertWarmEqualsCold($routes, $routes, $engine);

    expect(emittedArray($warm)['components']['schemas'])->toHaveKey('StoreShipmentRequestDeliveryPickup');
});

it('publishes the union to OpenAPI 3.0 with the discriminator and the tag as a one-value enum', function () use ($engine, $routes): void {
    $emitted = (new OpenApi30DownlevelEmitter)->emit(localityBuild($routes, $engine)->document);
    $schemas = json_decode($emitted, true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];

    // A nullable delivery keeps null beside the union, spelled with a `type` for 3.0's `nullable` to widen.
    expect($schemas['StoreShipmentRequestDeliveryPickup']['properties']['method'])->toEqual(['allOf' => [['$ref' => '#/components/schemas/DeliveryMethod']], 'enum' => ['pickup']])
        ->and($schemas['StoreShipmentRequest']['properties']['delivery'])->not->toHaveKey('nullable')
        ->and(openApi30Admits($emitted, '/components/schemas/StoreShipmentRequest/properties/delivery', null))->toBeTrue();
});

it('keeps the merged object where a second field gates members of it too', function () use ($engine): void {
    $result = localityBuild(static function (Router $router): void {
        $router->post('api/zz-shipments', [ShipmentController::class, 'storeMixed']);
    }, $engine);

    $schemas = emittedArray($result)['components']['schemas'];
    $delivery = $schemas['StoreMixedShipmentRequest']['properties']['delivery'];

    // No single value decides which members a request carries, so there is no branch per value to publish.
    expect($delivery)->not->toHaveKey('anyOf')
        ->and($delivery['properties']['address']['description'])->toBe('Required when delivery.method is courier or locker.')
        ->and($delivery['properties']['tracking']['description'])->toBe('Required when delivery.mode is express.')
        ->and(array_keys($schemas))->toBe(['DeliveryMethod', 'StoreMixedShipmentRequest']);
});

it('publishes a body tagged by its own member as the union, and requires the body', function () use ($engine, $notices): void {
    $result = localityBuild($notices, $engine);
    $document = emittedArray($result);
    $schemas = $document['components']['schemas'];

    expect(array_diff_key($schemas['StoreNoticeRequest'], ['x-docuccino' => true]))->toBe([
        'oneOf' => [
            ['$ref' => '#/components/schemas/StoreNoticeRequestEmail'],
            ['$ref' => '#/components/schemas/StoreNoticeRequestSms'],
        ],
        'discriminator' => [
            'mapping' => [
                'email' => '#/components/schemas/StoreNoticeRequestEmail',
                'sms' => '#/components/schemas/StoreNoticeRequestSms',
            ],
            'propertyName' => 'channel',
        ],
    ])
        ->and($schemas['StoreNoticeRequestSms']['required'])->toBe(['channel', 'body', 'phone'])
        // No member of the merged root required anything once the tag's own rule moved onto the
        // partition — and yet no body the server accepts lacks the tag.
        ->and($document['paths']['/api/zz-notices']['post']['requestBody']['required'])->toBeTrue();
});

it('accepts exactly the tagged bodies the server accepts', function (array $body, bool $accepted) use ($engine, $notices): void {
    expect(requestRuleVerdicts(StoreNoticeRequest::class, $body, $notices, $engine))->toBe([$accepted, $accepted]);
})->with([
    'email' => [['channel' => 'email', 'address' => 'a@example.com', 'body' => 'hi'], true],
    'sms, an address sent too and ignored' => [['channel' => 'sms', 'phone' => '0700', 'address' => 'not an email', 'body' => 'hi'], true],
    'sms without its phone' => [['channel' => 'sms', 'body' => 'hi'], false],
    'no channel' => [['address' => 'a@example.com', 'body' => 'hi'], false],
    'the empty body' => [[], false],
]);

it('keeps the union beside a key the request copies from a header', function () use ($engine): void {
    $result = localityBuild(static function (Router $router): void {
        $router->post('api/zz-shipments', [ShipmentController::class, 'storeTracked']);
    }, $engine);
    $schemas = emittedArray($result)['components']['schemas'];

    // The copied key leaves the body, and nothing the partition is read off went with it.
    expect($schemas['StoreTrackedShipmentRequest']['properties'])->toHaveKeys(['delivery'])
        ->and($schemas['StoreTrackedShipmentRequest']['properties'])->not->toHaveKey('tracking_key')
        ->and($schemas['StoreTrackedShipmentRequest']['properties']['delivery']['anyOf'][0]['discriminator']['propertyName'])->toBe('method')
        ->and($schemas['StoreTrackedShipmentRequestDeliveryCourier']['required'])->toBe(['method', 'address']);
});

it('publishes the merged body where the key a partition is read off is copied from elsewhere', function (string $action): void {
    $engine = WorkbenchEngine::make(traceOverrides: [
        StoreRoutedNoticeRequest::class.'::rules' => TraceScript::forMethod((string) (new ReflectionClass(StoreRoutedNoticeRequest::class))->getFileName(), StoreRoutedNoticeRequest::class, 'rules'),
        StoreDialledNoticeRequest::class.'::rules' => TraceScript::forMethod((string) (new ReflectionClass(StoreDialledNoticeRequest::class))->getFileName(), StoreDialledNoticeRequest::class, 'rules'),
    ]);
    $request = $action === 'notifyRouted' ? 'StoreRoutedNoticeRequest' : 'StoreDialledNoticeRequest';
    $document = emittedArray(localityBuild(static function (Router $router) use ($action): void {
        $router->post('api/zz-notices', [ShipmentController::class, $action]);
        $router->post('api/zz-notices-inline', [InlineNoticeController::class, $action]);
    }, static fn (): TypeEngine => $engine));
    $schemas = $document['components']['schemas'];

    // The oracle is the same rules on a route that proves no partition at all — an operation-level
    // declaration keeps its body inline — less the one field that declaration adds. A body that is no
    // longer split by its tag must read exactly as that, not as the merged object with the presence rules
    // the split moved off it missing: a `required` tag published optional, "Required when" gone.
    $inline = $document['paths']['/api/zz-notices-inline']['post']['requestBody']['content']['application/json']['schema'];
    unset($inline['properties']['trace']);

    expect(array_diff_key($schemas[$request], ['x-docuccino' => true]))->toBe($inline)
        ->and(array_keys($schemas))->not->toContain($request.'Email', $request.'Sms')
        ->and($inline['properties']['address']['description'])->toBe('Required when channel is email.');
})->with(['the tag' => ['notifyRouted'], 'a member the tag switches' => ['notifyDialled']]);
