<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ForceWrappedResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\KeyedCollectionController;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\KeyedReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResource;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Router;
use Illuminate\Support\Fluent;
use Opis\JsonSchema\Validator;

/**
 * A list of a resource that preserves its keys, through the pipeline: Laravel sends kept keys as an
 * object unless they run 0…n-1, paginated or not, so the list is published as the array or object it may
 * be — and the page of that item is its own, since the page of a renumbered item is always a list.
 */
afterEach(fn () => removeFragmentCacheDirs('keyed-collection'));

beforeEach(function (): void {
    $location = new SourceLocation('');
    $tag = new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([new ArrayShapeField('tag', ScalarT::string())]), $location)]);
    $keyed = new ActionAnalysis(returns: [new ReturnSite(new ClassT(ResourceReflector::ANONYMOUS_COLLECTION, [new ClassT(KeyedReleaseResource::class)]), $location)]);
    $paginates = TraceScript::forChain('$q->paginate(15)', 'Illuminate\\Database\\Eloquent\\Builder');

    $this->engine = static fn (): TypeEngine => WorkbenchEngine::make(
        analysisOverrides: [
            KeyedCollectionController::class.'::index' => $keyed,
            KeyedCollectionController::class.'::paginated' => $keyed,
            KeyedCollectionController::class.'::renumbered' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(ResourceReflector::ANONYMOUS_COLLECTION, [new ClassT(ReleaseResource::class)]), $location)]),
            KeyedCollectionController::class.'::forced' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(ForceWrappedResource::class), $location)]),
            KeyedReleaseResource::class.'::toArray' => $tag,
            ReleaseResource::class.'::toArray' => $tag,
            ForceWrappedResource::class.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('source', ScalarT::string())])),
                new ArrayShapeField('tag', ScalarT::string()),
            ]), $location)]),
        ],
        traceOverrides: [
            KeyedCollectionController::class.'::paginated' => $paginates,
            KeyedCollectionController::class.'::renumbered' => $paginates,
        ],
    );

    $this->routes = static function (Router $router): void {
        $router->get('api/zz-keyed', [KeyedCollectionController::class, 'index']);
        $router->get('api/zz-keyed-pages', [KeyedCollectionController::class, 'paginated']);
        $router->get('api/zz-renumbered-pages', [KeyedCollectionController::class, 'renumbered']);
        $router->get('api/zz-forced', [KeyedCollectionController::class, 'forced']);
    };

    // The published schema with every `$ref` inlined, as a validator reads it.
    $this->inline = static function (array $document, mixed $schema): mixed {
        $inline = static function (mixed $node, ?string $key = null) use (&$inline, $document): mixed {
            if (! is_array($node)) {
                return $node;
            }
            if (isset($node['$ref']) && is_string($node['$ref'])) {
                return $inline($document['components']['schemas'][substr($node['$ref'], strlen('#/components/schemas/'))]);
            }
            if ($node === [] && ! in_array($key, ['required', 'enum'], true)) {
                return new stdClass;
            }
            $out = [];
            foreach ($node as $k => $v) {
                $out[$k] = $inline($v, is_string($k) ? $k : $key);
            }

            return array_is_list($out) && in_array($key, ['required', 'enum', 'type', 'examples', 'anyOf', 'allOf', 'oneOf'], true) ? $out : (object) $out;
        };

        return $inline($schema);
    };
});

it('publishes a list that keeps its keys, paginated or not, as the array or object Laravel sends', function (): void {
    $document = localityBuild($this->routes, $this->engine)->document->toArray();
    $body = static fn (string $path): array => $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];
    $page = static fn (string $path): array => $document['components']['schemas'][substr($body($path)['$ref'], strlen('#/components/schemas/'))];
    $accepts = fn (string $path, mixed $sent): bool => (new Validator)->validate($sent, ($this->inline)($document, $body($path)))->isValid();

    $keyed = collect([3 => new Fluent(['tag' => 'a']), 7 => new Fluent(['tag' => 'b'])]);
    $send = static fn (JsonResource $resource): mixed => json_decode((string) $resource->toResponse(Request::create('/'))->getContent());
    $plain = $send(KeyedReleaseResource::collection($keyed));
    $paged = $send(KeyedReleaseResource::collection(new LengthAwarePaginator($keyed, 2, 15)));
    $renumbered = $send(ReleaseResource::collection(new LengthAwarePaginator($keyed, 2, 15)));

    expect($plain->data)->toBeObject()
        ->and($paged->data)->toBeObject()
        ->and($renumbered->data)->toBeArray()
        ->and($body('/api/zz-keyed')['properties']['data']['type'])->toBe(['array', 'object'])
        ->and($page('/api/zz-keyed-pages')['properties']['data']['type'])->toBe(['array', 'object'])
        // The page of a renumbered item stays the list every page of it is, under a name of its own.
        ->and($page('/api/zz-renumbered-pages')['properties']['data']['type'])->toBe('array')
        ->and($body('/api/zz-renumbered-pages')['$ref'])->not->toBe($body('/api/zz-keyed-pages')['$ref'])
        ->and($accepts('/api/zz-keyed', $plain))->toBeTrue()
        ->and($accepts('/api/zz-keyed-pages', $paged))->toBeTrue()
        ->and($accepts('/api/zz-renumbered-pages', $renumbered))->toBeTrue()
        // A renumbered page is never sent keyed, so the list stands against one that is.
        ->and($accepts('/api/zz-renumbered-pages', $paged))->toBeFalse();
});

it('wraps a body carrying its own wrap key where the resource forces its wrap', function (): void {
    $document = localityBuild($this->routes, $this->engine)->document->toArray();
    $schema = ($this->inline)($document, $document['paths']['/api/zz-forced']['get']['responses']['200']['content']['application/json']['schema']);
    $sent = json_decode((string) (new ForceWrappedResource(new Fluent(['tag' => 'a'])))->toResponse(Request::create('/'))->getContent());

    expect((new Validator)->validate($sent, $schema)->isValid())->toBeTrue()
        ->and(property_exists($sent, 'tag'))->toBe(! property_exists(JsonResource::class, 'forceWrapping'));
});

it('keys a list on the file of the resource whose keys it keeps, and a warm build equals a cold one', function (): void {
    $dir = fragmentCacheDir('keyed-collection');

    $cold = localityBuild($this->routes, $this->engine);
    $warm = localityBuild($this->routes, $this->engine, $counting);

    // The action names only the framework's collection: the resource's $preserveKeys decides the list.
    $fixtures = dirname(__DIR__, 2).'/Fixtures/ApiResources';
    expect($counting)->toBeInstanceOf(CountingTypeEngine::class)
        ->and($counting->analyzeCount)->toBe(0)
        ->and((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
        ->and(fragmentEntries($dir)['get /api/zz-keyed']['dependencies'])->toContain($fixtures.'/KeyedReleaseResource.php')
        ->and(fragmentEntries($dir)['get /api/zz-keyed-pages']['dependencies'])->toContain($fixtures.'/KeyedReleaseResource.php')
        ->and(fragmentEntries($dir)['get /api/zz-forced']['dependencies'])->toContain($fixtures.'/ForceWrappedResource.php');
});

it('rebuilds a resource response when a wrap static set at boot changes, so a warm build equals a cold one', function (string $static, Closure $set, Closure $restore): void {
    // `JsonResource::withoutWrapping()`, `::wrap()` and a `$forceWrapping` assignment are boot code in a
    // service provider: no route file records them, and each decides the body Laravel sends.
    if ($static === 'forceWrapping' && ! property_exists(JsonResource::class, 'forceWrapping')) {
        $this->markTestSkipped('The installed framework has no $forceWrapping.');
    }

    fragmentCacheDir('keyed-collection');
    $before = (new UirEmitter)->emit(localityBuild($this->routes, $this->engine)->document);

    try {
        $set();
        $warm = localityBuild($this->routes, $this->engine, $counting);

        fragmentCacheDir('keyed-collection');
        $cold = (new UirEmitter)->emit(localityBuild($this->routes, $this->engine)->document);
    } finally {
        $restore();
    }

    // The toggle changes what is published, or the comparison below proves nothing.
    expect($cold)->not->toBe($before)
        ->and((new UirEmitter)->emit($warm->document))->toBe($cold);
})->with([
    'the base wrap cleared' => ['wrap', static fn () => JsonResource::withoutWrapping(), static fn () => JsonResource::wrap('data')],
    'the base wrap renamed' => ['wrap', static fn () => JsonResource::wrap('item'), static fn () => JsonResource::wrap('data')],
    "a resource's own forced wrap turned off" => ['forceWrapping', static function (): void {
        ForceWrappedResource::$forceWrapping = false;
    }, static function (): void {
        ForceWrappedResource::$forceWrapping = true;
    }],
]);
