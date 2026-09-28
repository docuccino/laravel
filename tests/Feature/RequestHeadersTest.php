<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Pipeline\DocumentGenerator;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\RequestHeadersController;
use Workbench\App\Http\Requests\PlaceOrderRequest;

/**
 * A header the server reads is an input the document owes the consumer: a client generated from a document
 * without it has no way to send it. What a read proves is presence and nothing more — `header()` answers
 * null when the header is absent and the action carries on — so each is published optional, typed as the
 * string every header value is on the wire.
 *
 * The stub engine scripts each trace over the REAL bodies in {@see RequestHeadersController} and
 * {@see PlaceOrderRequest}, walking a callee after its caller as the engine's descent does. Routes are
 * registered ad-hoc so no other committed golden churns.
 *
 * @param  array<string, mixed>  $settings  merged over the document's build settings
 * @return array<string, mixed>
 */
function requestHeadersDocument(array $settings = [], bool $golden = false): array
{
    $controller = (string) (new ReflectionClass(RequestHeadersController::class))->getFileName();
    $formRequest = (string) (new ReflectionClass(PlaceOrderRequest::class))->getFileName();
    $request = new ClassT('Illuminate\\Http\\Request');
    $placeOrder = new ClassT(PlaceOrderRequest::class);

    $store = TraceScript::forMethod($controller, RequestHeadersController::class, 'store', ['request' => $placeOrder]);
    $callee = TraceScript::forMethod($formRequest, PlaceOrderRequest::class, 'idempotencyKey', ['this' => $placeOrder]);

    $symbol = RequestHeadersController::class.'::';
    app()->instance(TypeEngine::class, WorkbenchEngine::make(traceOverrides: [
        $symbol.'store' => static function (TraceVisitor $visitor) use ($store, $callee): void {
            $store($visitor);
            $callee($visitor);
        },
        $symbol.'trace' => TraceScript::forMethod($controller, RequestHeadersController::class, 'trace', ['request' => $request]),
        $symbol.'quiet' => TraceScript::forMethod($controller, RequestHeadersController::class, 'quiet', ['request' => $request]),
        $symbol.'pinned' => TraceScript::forMethod($controller, RequestHeadersController::class, 'pinned', ['request' => $request]),
        PlaceOrderRequest::class.'::authorize' => TraceScript::forMethod($formRequest, PlaceOrderRequest::class, 'authorize', ['this' => $placeOrder]),
        PlaceOrderRequest::class.'::withValidator' => TraceScript::forMethod($formRequest, PlaceOrderRequest::class, 'withValidator', ['this' => $placeOrder]),
        PlaceOrderRequest::class.'::prepareForValidation' => TraceScript::forMethod($formRequest, PlaceOrderRequest::class, 'prepareForValidation', ['this' => $placeOrder]),
    ]));

    /** @var Router $router */
    $router = app('router');
    $router->post('api/orders', [RequestHeadersController::class, 'store']);
    $router->get('api/traced/{dynamic}', [RequestHeadersController::class, 'trace']);
    $router->get('api/pinned', [RequestHeadersController::class, 'pinned']);
    $router->get('api/quiet', [RequestHeadersController::class, 'quiet']);

    /** @var array<string, mixed> $raw */
    $raw = array_replace(documentSettings(), $settings);
    $raw['info'] = ['title' => 'Request Headers API', 'version' => '1.0.0'];
    $raw['routes'] = ['include' => ['api/orders', 'api/traced/*', 'api/pinned', 'api/quiet']];

    $config = app(DocumentConfigFactory::class)->make('request-headers', $raw, 'skeleton');
    $emitted = (new UirEmitter)->emit(app(DocumentGenerator::class)->generate($config, app(TypeEngine::class))->document);

    if ($golden) {
        assertGolden('workbench-request-headers.uir.json', $emitted);
    }

    return json_decode($emitted, true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The header parameters one operation publishes, by name, without the provenance the UIR carries.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, array<string, mixed>>
 */
function requestHeaderParameters(array $document, string $path, string $method): array
{
    $strip = static function (mixed $value) use (&$strip): mixed {
        if (! is_array($value)) {
            return $value;
        }
        unset($value['x-docuccino']);

        return array_map($strip, $value);
    };

    $byName = [];
    foreach ($document['paths'][$path][$method]['parameters'] ?? [] as $parameter) {
        if (($parameter['in'] ?? null) === 'header') {
            $byName[$parameter['name']] = $strip($parameter);
        }
    }

    return $byName;
}

it('emits the request-headers document byte-identical to its committed golden', function (): void {
    requestHeadersDocument(golden: true);
});

it('publishes a header read in a FormRequest method the action calls, and in the ones the framework runs', function (): void {
    $headers = requestHeaderParameters(requestHeadersDocument(), '/api/orders', 'post');

    $optional = static fn (string $name): array => ['name' => $name, 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string']];

    // `withValidator()` is a method the framework looks for by name and `prepareForValidation()` one it
    // overrides; both run on every request the route serves, so what they read the server reads.
    // `X-Service-Token` is read only by the gate: without it the server answers 403, so it is no optional
    // input, and it is a credential the API never offered a client — so it is not published at all.
    expect($headers)->toBe([
        'Idempotency-Key' => $optional('Idempotency-Key'),
        'X-Client-Version' => $optional('X-Client-Version'),
        'X-Locale' => $optional('X-Locale'),
    ]);
});

it('publishes one parameter per header however many spellings read it, and nothing a client cannot send', function (): void {
    $headers = requestHeaderParameters(requestHeadersDocument(), '/api/traced/{dynamic}', 'get');

    // `X-Request-Id` and `x-request-id` are one header on the wire, so one parameter. `Accept` is a header
    // OpenAPI says is not a parameter, the dynamic name is not one this build can name, and the
    // response's `header()` setter is not a read at all.
    expect(array_keys($headers))->toBe(['If-None-Match', 'X-Api-Version', 'X-Request-Id'])
        ->and($headers['If-None-Match']['required'])->toBeFalse();
});

it('leaves the API version header to the declaration a versioned document publishes for it', function (): void {
    $document = requestHeadersDocument(['api_version' => []]);
    $parameters = $document['paths']['/api/traced/{dynamic}']['get']['parameters'];

    // The document's own declaration enumerates the versions and names the default; a read of the same
    // header knows neither, and publishing it would stop the operation pointing at the one that does.
    expect(array_keys(requestHeaderParameters($document, '/api/traced/{dynamic}', 'get')))->toBe(['If-None-Match', 'X-Request-Id'])
        ->and(array_column($parameters, '$ref'))->toBe(['#/components/parameters/XApiVersion']);
});

it('leaves a header the author declared under another case exactly as they declared it', function (): void {
    $headers = requestHeaderParameters(requestHeadersDocument(), '/api/pinned', 'get');

    expect($headers)->toBe([
        'idempotency-key' => [
            'name' => 'idempotency-key',
            'in' => 'header',
            'description' => 'Makes a retried request safe to repeat.',
            'required' => true,
            'schema' => ['type' => 'string', 'format' => 'uuid'],
        ],
    ]);
});

it('leaves a header a configured apiKey scheme carries to the scheme', function (): void {
    $document = requestHeadersDocument(['security' => ['schemes' => [
        'requestKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'x-request-id'],
    ]]]);

    expect(array_keys(requestHeaderParameters($document, '/api/traced/{dynamic}', 'get')))->toBe(['If-None-Match', 'X-Api-Version']);
});

it('drops a header it read when the author ignores it by the name it publishes', function (): void {
    // The one way to keep a header the code reads out of the contract, so it has to reach this producer's
    // parameters as it reaches any other's.
    expect(array_keys(requestHeaderParameters(requestHeadersDocument(), '/api/quiet', 'get')))->toBe(['X-Request-Id']);
});
