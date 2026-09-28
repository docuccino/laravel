<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\PayloadStatusT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\RenderedResourceController;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;

/**
 * A resource returned through the response the framework renders from it, locked in emitted bytes. The
 * router sends a returned resource through its `toResponse()`, and `response()` is that same call, so the
 * document owes each form the body the bare resource gets — its `with()` members and wrap included — under
 * the status the resource decides (a created model's 201) unless the chain states another. The analyses
 * are the types the real engine recovers for these shapes (`ResourceRenderingTest`); this golden is the
 * document built from them, and no other golden carries a resource returned any way but bare.
 */
afterEach(fn () => removeFragmentCacheDirs('rendered-resource'));

beforeEach(function (): void {
    $location = new SourceLocation('');
    $site = static fn (DType $type): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite($type, $location)]);
    $rendered = static fn (DType $payload, ?int $status = null): ClassT => new ClassT(
        'Illuminate\\Http\\JsonResponse',
        [$payload, $status === null ? new PayloadStatusT : new LiteralT($status)],
    );
    $resource = new ClassT(ReleaseResource::class);
    $collection = new ClassT('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection', [$resource]);
    $action = static fn (string $method): string => RenderedResourceController::class.'::'.$method;
    $creates = TraceScript::forChain('new \\'.ReleaseResource::class.'(\\'.Widget::class.'::create([]))', 'Illuminate\\Database\\Eloquent\\Builder');

    $this->engine = static fn (): TypeEngine => WorkbenchEngine::make(
        analysisOverrides: [
            $action('plain') => $site($resource),
            $action('response') => $site($rendered($resource)),
            $action('toResponse') => $site($rendered($resource)),
            $action('created') => $site($rendered($resource, 201)),
            $action('store') => $site($rendered($resource)),
            $action('restated') => $site($rendered($resource, 200)),
            $action('collection') => $site($rendered($collection)),
            $action('paginated') => $site($rendered($collection)),
            $action('named') => $site($rendered(new ClassT(ReleaseCollection::class))),
            $action('relabelled') => $site(new ClassT('Illuminate\\Http\\JsonResponse', [$resource, new PayloadStatusT, new LiteralT('application/vnd.release+json')])),
            // Two responses from one return: a guard arm of the resource's own `toResponse()`, and the
            // framework's rendering it hands every other request back to.
            $action('guarded') => new ActionAnalysis(returns: [
                new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse', [new ArrayShapeT([new ArrayShapeField('message', ScalarT::string())]), new LiteralT(410)]), $location),
                new ReturnSite($rendered($resource), $location),
            ]),
            ReleaseResource::class.'::toArray' => $site(new ArrayShapeT([new ArrayShapeField('tag', ScalarT::string())])),
            ReleaseResource::class.'::with' => $site(new ArrayShapeT([
                new ArrayShapeField('meta', new ClassT('stdClass')),
                new ArrayShapeField('version', ScalarT::string()),
            ])),
            ReleaseCollection::class.'::with' => $site(new ArrayShapeT([
                new ArrayShapeField('meta', new ArrayShapeT([new ArrayShapeField('key', ScalarT::string())])),
            ])),
        ],
        traceOverrides: [
            $action('store') => $creates,
            $action('restated') => $creates,
            $action('relabelled') => $creates,
            $action('paginated') => TraceScript::forChain('$q->paginate(15)', 'Illuminate\\Database\\Eloquent\\Builder'),
        ],
    );

    $this->routes = static function (Router $router): void {
        foreach (['plain', 'response', 'toResponse', 'collection', 'paginated', 'named', 'guarded'] as $method) {
            $router->get('api/zz-rendered/'.$method, [RenderedResourceController::class, $method]);
        }
        foreach (['created', 'store', 'restated', 'relabelled'] as $method) {
            $router->post('api/zz-rendered/'.$method, [RenderedResourceController::class, $method]);
        }
    };
});

it('publishes a framework-rendered resource byte-identical to its committed golden', function (): void {
    $result = localityBuild($this->routes, $this->engine);

    assertGolden('rendered-resource.uir.json', (new UirEmitter)->emit($result->document));
});

it('owes every rendered form the body the bare resource gets, under the status the server sends', function (): void {
    $result = localityBuild($this->routes, $this->engine);
    $document = emittedArray($result);
    $responses = static fn (string $path, string $verb = 'get'): array => $document['paths']['/api/zz-rendered/'.$path][$verb]['responses'];
    // Which layer wrote a body is provenance, not contract: the created-model 201 is written by the
    // integration that re-homes it, the rest by inference.
    $body = static fn (array $response): array => array_diff_key($response['content']['application/json']['schema'], ['x-docuccino' => true]);

    $plain = $body($responses('plain')['200']);

    // The bare envelope, `with()` members beside `data`, is what `ResourceResponse::toResponse()` sends
    // for every one of these, so each is that same schema, byte for byte.
    expect(array_keys($plain['properties']))->toBe(['data', 'meta', 'version'])
        ->and($body($responses('response')['200']))->toBe($plain)
        ->and($body($responses('toResponse')['200']))->toBe($plain)
        // The chain states the status; the body is still the resource's.
        ->and(array_keys($responses('created', 'post')))->toBe([201])
        ->and($body($responses('created', 'post')['201']))->toBe($plain)
        // Left to the resource, a freshly created model is a 201 — `calculateStatus()` reads
        // `wasRecentlyCreated` whichever way the resource was rendered…
        ->and(array_keys($responses('store', 'post')))->toBe([201])
        ->and($body($responses('store', 'post')['201']))->toBe($plain)
        // …and a status the chain states afterwards is the one the server sends.
        ->and(array_keys($responses('restated', 'post')))->toBe([200])
        // A collection is its list under `data`, and a paginated one its page envelope.
        ->and($body($responses('collection')['200'])['properties']['data']['items'])->toBe(['$ref' => '#/components/schemas/ReleaseResource'])
        ->and(resolveSchema($document, $body($responses('paginated')['200']))['required'])->toBe(['data', 'links', 'meta'])
        ->and($body($responses('named')['200'])['required'])->toBe(['data', 'meta'])
        // A media type stamped on the rendering leaves the status the resource's: still the created 201,
        // carrying the same envelope.
        ->and(array_keys($responses('relabelled', 'post')))->toBe([201])
        ->and($body($responses('relabelled', 'post')[201]))->toBe($plain)
        // The guard arm and the envelope, each under its own status.
        ->and(array_keys($responses('guarded')))->toBe([200, 410])
        ->and(array_diff_key($responses('guarded')[200]['content']['application/json']['schema'], ['x-docuccino' => true]))->toBe($plain);

    // Nothing here is a body the analyzer failed to read, so nothing tells the author to restructure it.
    $codes = array_map(static fn ($d): string => $d->code, $result->diagnostics);
    expect($codes)->not->toContain('inferred-response.payload-unrecoverable');
});

it('keys each rendered route on the resource it renders, and a warm build equals a cold one', function (): void {
    $dir = fragmentCacheDir('rendered-resource');

    $cold = localityBuild($this->routes, $this->engine);
    $warm = localityBuild($this->routes, $this->engine, $counting);

    expect($counting->analyzeCount)->toBe(0)
        ->and((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
        ->and(fragmentEntries($dir)['get /api/zz-rendered/response']['dependencies'])
        ->toContain(dirname(__DIR__, 2).'/Fixtures/ApiResources/EnvelopedResource.php');
});
