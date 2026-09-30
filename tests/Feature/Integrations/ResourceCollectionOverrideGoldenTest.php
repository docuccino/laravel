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
use Docuccino\Laravel\Integrations\Support\PageLinks;
use Docuccino\Laravel\Integrations\Support\PaginationEnvelope;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\AppendedCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\CatalogueResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ListedCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ListedCollectionController;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\TalliedCollection;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Router;
use Opis\JsonSchema\Validator;

/**
 * A list of a resource whose family overrides `newCollection()`, locked in emitted bytes. Laravel's
 * `collection()` calls `static::newCollection()`, so the list is sent as the override's collection —
 * its `with()` members beside `data`, paginated or not — and the engine answers it as that collection of
 * the item. A list of a resource keeping the framework's `newCollection()` is the control: the item's own
 * `with()` is the ROOT resource's hook, and a collection's root is the collection.
 */
afterEach(fn () => removeFragmentCacheDirs('listed-collection'));

beforeEach(function (): void {
    $location = new SourceLocation('');
    $shape = static fn (array $fields): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT($fields), $location)]);
    $listed = new ActionAnalysis(returns: [new ReturnSite(new ClassT(ListedCollection::class, [new ClassT(CatalogueResource::class)]), $location)]);

    $this->engine = static fn (): TypeEngine => WorkbenchEngine::make(
        analysisOverrides: [
            ListedCollectionController::class.'::index' => $listed,
            ListedCollectionController::class.'::paginated' => $listed,
            ListedCollectionController::class.'::tallied' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(TalliedCollection::class, [new ClassT(CatalogueResource::class)]), $location)]),
            ListedCollectionController::class.'::appended' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(AppendedCollection::class, [new ClassT(CatalogueResource::class)]), $location)]),
            AppendedCollection::class.'::with' => $shape([
                new ArrayShapeField('data', new ArrayShapeT([new ArrayShapeField('source', ScalarT::string())])),
            ]),
            TalliedCollection::class.'::with' => $shape([
                new ArrayShapeField('meta', new ArrayShapeT([
                    new ArrayShapeField('total', ScalarT::int()),
                    new ArrayShapeField('source', ScalarT::string()),
                ])),
            ]),
            ListedCollectionController::class.'::plain' => new ActionAnalysis(returns: [new ReturnSite(new ClassT(ResourceReflector::ANONYMOUS_COLLECTION, [new ClassT(ReleaseResource::class)]), $location)]),
            CatalogueResource::class.'::toArray' => $shape([new ArrayShapeField('name', ScalarT::string())]),
            ListedCollection::class.'::with' => $shape([
                new ArrayShapeField('meta', new ArrayShapeT([new ArrayShapeField('listed_at', ScalarT::string())])),
                new ArrayShapeField('api_version', ScalarT::string()),
            ]),
            ReleaseResource::class.'::toArray' => $shape([new ArrayShapeField('tag', ScalarT::string())]),
        ],
        traceOverrides: [
            ListedCollectionController::class.'::paginated' => TraceScript::forChain('$q->paginate(15)', 'Illuminate\\Database\\Eloquent\\Builder'),
            ListedCollectionController::class.'::tallied' => TraceScript::forChain('$q->paginate(15)', 'Illuminate\\Database\\Eloquent\\Builder'),
            ListedCollectionController::class.'::appended' => TraceScript::forChain('$q->paginate(15)', 'Illuminate\\Database\\Eloquent\\Builder'),
        ],
    );

    $this->routes = static function (Router $router): void {
        $router->get('api/zz-listed', [ListedCollectionController::class, 'index']);
        $router->get('api/zz-listed-pages', [ListedCollectionController::class, 'paginated']);
        $router->get('api/zz-plain', [ListedCollectionController::class, 'plain']);
    };
});

it('emits a list built by a newCollection() override byte-identical to its committed golden', function (): void {
    $result = localityBuild($this->routes, $this->engine);

    assertGolden('resource-collection-override.uir.json', (new UirEmitter)->emit($result->document));

    $document = $result->document->toArray();
    $body = static fn (string $path): array => $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];

    // Laravel's ResourceResponse merges the collection's with() beside the wrapped data, and this one
    // always returns both members.
    expect(array_keys($body('/api/zz-listed')['properties']))->toBe(['data', 'meta', 'api_version'])
        ->and($body('/api/zz-listed')['required'])->toBe(['data', 'meta', 'api_version'])
        ->and($body('/api/zz-listed')['properties']['data']['items'])->toBe(['$ref' => '#/components/schemas/CatalogueResource']);

    // Paginated, PaginatedResourceResponse merges with() into the pagination information recursively:
    // the page keeps its own links and meta, and the object `meta` extends it — both at once.
    $page = $body('/api/zz-listed-pages');
    expect($page['allOf'][0])->toBe(['$ref' => '#/components/schemas/CatalogueResourcePage'])
        ->and(array_keys($page['allOf'][1]['properties']))->toBe(['meta', 'api_version'])
        ->and($page['allOf'][1]['required'])->toBe(['meta', 'api_version'])
        // The page component is every collection of this item's, so the members are never folded into it.
        ->and(array_keys($document['components']['schemas']['CatalogueResourcePage']['properties']))->toBe(['data', 'links', 'meta']);

    // The framework's collection returns its $with property, empty by default, and never the item's with().
    expect(array_keys($body('/api/zz-plain')['properties']))->toBe(['data']);
});

it('keys the list on the file declaring the override, and a warm build equals a cold one', function (): void {
    $dir = fragmentCacheDir('listed-collection');

    $cold = localityBuild($this->routes, $this->engine);
    $warm = localityBuild($this->routes, $this->engine, $counting);

    // The action and the item name the collection nowhere: removing the override from the base is what
    // turns the list back into the framework's, so that file has to retire the fragment — as does the
    // collection class whose with() the envelope reads.
    $fixtures = dirname(__DIR__, 2).'/Fixtures/ApiResources';
    expect($counting)->toBeInstanceOf(CountingTypeEngine::class)
        ->and($counting->analyzeCount)->toBe(0)
        ->and((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
        ->and(fragmentEntries($dir)['get /api/zz-listed']['dependencies'])
        ->toContain($fixtures.'/ListedResource.php', $fixtures.'/ListedCollection.php');
});

it('publishes a with() meta key the page also sends as the array Laravel merges the two into', function (): void {
    // PaginatedResourceResponse merges with() into the pagination information with array_merge_recursive,
    // so a key both send is sent as the list of both values: the page component's integer is not it.
    $result = localityBuild(static function (Router $router): void {
        $router->get('api/zz-tallied', [ListedCollectionController::class, 'tallied']);
    }, $this->engine);
    $document = $result->document->toArray();
    $body = $document['paths']['/api/zz-tallied']['get']['responses']['200']['content']['application/json']['schema'];

    $sent = json_decode((string) (new TalliedCollection(new LengthAwarePaginator([(object) ['name' => 'a']], 2, 15), CatalogueResource::class))
        ->toResponse(Request::create('/'))->getContent());

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

    expect($sent->meta->total)->toBe([2, 5])
        ->and($sent->meta->source)->toBe('ledger')
        ->and((new Validator)->validate($sent, $inline($body))->isValid())->toBeTrue()
        // The page component still states every other page's integer, so this page is restated inline.
        ->and($body['allOf'] ?? null)->toBeNull()
        ->and($body['properties']['links'])->toBe(['$ref' => '#/components/schemas/PaginationLinks'])
        ->and($body['properties']['meta']['properties']['total'])->toBe(['type' => ['array', 'object']])
        ->and($body['properties']['meta']['properties']['source'])->toBe(['type' => 'string'])
        ->and($body['properties']['meta']['properties']['per_page'])->toBe(['type' => 'integer'])
        ->and($body['properties']['meta']['required'])->toBe(['total', 'source']);

    // Invalid without the widening: a validator that accepts anything proves nothing.
    $claimed = $body;
    $claimed['properties']['meta']['properties']['total'] = ['type' => 'integer'];
    expect((new Validator)->validate($sent, $inline($claimed))->isValid())->toBeFalse();
});

it('publishes the data a with() data key is merged into, paginated, as the array or object it is sent as', function (): void {
    // with()'s data is merged into the page's data by array_merge_recursive, so the list the page component
    // states is not what is sent: its items and the member's keys arrive as one object.
    $result = localityBuild(static function (Router $router): void {
        $router->get('api/zz-appended', [ListedCollectionController::class, 'appended']);
    }, $this->engine);
    $document = $result->document->toArray();
    $body = $document['paths']['/api/zz-appended']['get']['responses']['200']['content']['application/json']['schema'];

    $sent = json_decode((string) (new AppendedCollection(new LengthAwarePaginator([(object) ['name' => 'a']], 2, 15), CatalogueResource::class))
        ->toResponse(Request::create('/'))->getContent());

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

    expect($sent->data)->toEqual((object) ['0' => (object) ['name' => 'widget'], 'source' => 'ledger'])
        ->and($sent->meta->total)->toBe(2)
        ->and((new Validator)->validate($sent, $inline($body))->isValid())->toBeTrue()
        // Still paginated: the page's own links and meta are stated beside the widened data.
        ->and($body['properties']['data'] ?? null)->toBe(PaginationEnvelope::MERGED)
        ->and($body['properties']['links'])->toBe(['$ref' => '#/components/schemas/PaginationLinks'])
        ->and($body['properties']['meta'])->toBe(['$ref' => '#/components/schemas/PaginationMeta'])
        // The page component is every collection of this item's, and keeps the list it states for them.
        ->and($document['components']['schemas']['CatalogueResourcePage']['properties']['data']['items'] ?? null)
        ->toBe(['$ref' => '#/components/schemas/CatalogueResource']);

    // Invalid with the page's list: a validator that accepts anything proves nothing.
    $claimed = $body;
    $claimed['properties']['data'] = ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CatalogueResource']];
    expect((new Validator)->validate($sent, $inline($claimed))->isValid())->toBeFalse();
});

it('names every key Laravel sends in a page\'s links and meta, as what it sends', function (string $kind, Closure $paginator): void {
    // Read off the response Laravel builds, so a key a paginator gains is a collision this test catches.
    $sent = json_decode((string) (new AnonymousResourceCollection($paginator(), CatalogueResource::class))
        ->toResponse(Request::create('/'))->getContent(), true);
    $stated = PaginationEnvelope::sent($kind, PageLinks::Laravel);

    foreach (['links', 'meta'] as $part) {
        $keys = array_keys($sent[$part]);
        sort($keys);
        $named = array_keys($stated[$part]);
        sort($named);
        expect($named)->toBe($keys);

        foreach ($sent[$part] as $key => $value) {
            $schema = json_decode((string) json_encode($stated[$part][$key] === [] ? new stdClass : $stated[$part][$key]));
            expect((new Validator)->validate(json_decode((string) json_encode($value)), $schema)->isValid())->toBeTrue("{$part}.{$key}");
        }
    }
})->with([
    'length' => ['length', fn (): LengthAwarePaginator => new LengthAwarePaginator([(object) ['name' => 'a']], 1, 15)],
    'simple' => ['simple', fn (): Paginator => new Paginator([(object) ['name' => 'a']], 15)],
    'cursor' => ['cursor', fn (): CursorPaginator => new CursorPaginator([(object) ['name' => 'a']], 15)],
]);
