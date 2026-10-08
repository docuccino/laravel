<?php

declare(strict_types=1);

use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\WrappedResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\CatalogueResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\DigestCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\DigestResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\EnvelopeBranchController;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\GazetteCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\GazetteResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ListedCollection;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Opis\JsonSchema\Validator;

/**
 * Lists of collections whose `with()` branches on the paginator `$this->resource` may be, locked in emitted
 * bytes. Laravel's `collectResource()` leaves `$this->resource` the page's paginator or the plain collection
 * it built, so each envelope is sent with the members its own branch returns: a plain list proven plain
 * publishes what the non-paginator branch always adds as required, a page publishes what the paginator
 * branch adds, and a list that cannot be proven either keeps every branch, each member optional.
 *
 * The analyses stand in for what the real engine answers for the same bodies, which
 * RealEngineIntegrationsTest pins against the fixture app's copies of these classes.
 */
afterEach(fn () => removeFragmentCacheDirs('collection-envelope'));

beforeEach(function (): void {
    $location = new SourceLocation('');
    $sites = static fn (array $types): ActionAnalysis => new ActionAnalysis(returns: array_map(static fn ($type): ReturnSite => new ReturnSite($type, $location), $types));
    $shape = static fn (array $fields, bool $object = false): ArrayShapeT => new ArrayShapeT($fields, isObject: $object);
    $narrowed = static fn (string $collection, string $resource): string => (new CallableRef('', $collection, 'with', narrowType: $resource, narrowToEvery: true, narrowProperty: 'resource'))->symbol();
    $returns = static fn (string $collection, string $resource): ActionAnalysis => new ActionAnalysis(returns: [new ReturnSite(new ClassT($collection, [new ClassT($resource)]), $location)]);
    $controller = (string) (new ReflectionClass(EnvelopeBranchController::class))->getFileName();
    $plainWalk = static fn (string $method, string $collection, string $variable, string $type): callable => TraceScript::forMethod(
        $controller,
        EnvelopeBranchController::class,
        $method,
        variableTypes: [$variable => new ClassT($type)],
        receiverFqcn: $collection,
    );

    // `parent::with()` returns the `$with` property, which the analyser reads as a list of anything.
    $parentWith = new ListT(new UnknownT('mixed'));
    $gazetteMeta = $shape([new ArrayShapeField('meta', $shape([], object: true))]);
    $digestCounted = $shape([new ArrayShapeField('meta', $shape([new ArrayShapeField('count', ScalarT::int())]))]);
    $digestSource = $shape([new ArrayShapeField('meta', $shape([new ArrayShapeField('source', new LiteralT('digest'))]))]);
    $empty = new ArrayShapeT([], isList: true);

    $this->engine = static fn (): TypeEngine => WorkbenchEngine::make(
        callables: [
            $narrowed(GazetteCollection::class, WrappedResource::PLAIN) => $sites([$gazetteMeta]),
            $narrowed(GazetteCollection::class, WrappedResource::PAGINATORS['length']) => $sites([$parentWith]),
            $narrowed(DigestCollection::class, WrappedResource::PLAIN) => $sites([$digestCounted, $empty]),
            $narrowed(DigestCollection::class, WrappedResource::PAGINATORS['cursor']) => $sites([$digestSource]),
        ],
        analysisOverrides: [
            EnvelopeBranchController::class.'::gazette' => $returns(GazetteCollection::class, GazetteResource::class),
            EnvelopeBranchController::class.'::gazettePages' => $returns(GazetteCollection::class, GazetteResource::class),
            EnvelopeBranchController::class.'::gazetteBuilt' => $returns(GazetteCollection::class, GazetteResource::class),
            EnvelopeBranchController::class.'::digest' => $returns(DigestCollection::class, DigestResource::class),
            EnvelopeBranchController::class.'::digestPages' => $returns(DigestCollection::class, DigestResource::class),
            EnvelopeBranchController::class.'::listed' => $returns(ListedCollection::class, CatalogueResource::class),
            EnvelopeBranchController::class.'::gazetteListed' => $returns(GazetteCollection::class, GazetteResource::class),
            GazetteCollection::class.'::with' => $sites([$parentWith, $gazetteMeta]),
            DigestCollection::class.'::with' => $sites([$digestCounted, $empty, $digestSource]),
            ListedCollection::class.'::with' => $sites([$shape([
                new ArrayShapeField('meta', $shape([new ArrayShapeField('listed_at', ScalarT::string())])),
                new ArrayShapeField('api_version', ScalarT::string()),
            ])]),
            GazetteResource::class.'::toArray' => $sites([$shape([new ArrayShapeField('name', ScalarT::string())])]),
            DigestResource::class.'::toArray' => $sites([$shape([new ArrayShapeField('name', ScalarT::string())])]),
            CatalogueResource::class.'::toArray' => $sites([$shape([new ArrayShapeField('name', ScalarT::string())])]),
        ],
        traceOverrides: [
            EnvelopeBranchController::class.'::gazette' => $plainWalk('gazette', GazetteCollection::class, 'users', 'Illuminate\\Database\\Eloquent\\Collection'),
            EnvelopeBranchController::class.'::gazettePages' => TraceScript::forChain('$q->paginate(15)', 'Illuminate\\Database\\Eloquent\\Builder'),
            EnvelopeBranchController::class.'::gazetteBuilt' => $plainWalk('gazetteBuilt', GazetteCollection::class, 'page', LengthAwarePaginator::class),
            EnvelopeBranchController::class.'::digest' => $plainWalk('digest', DigestCollection::class, 'users', 'Illuminate\\Database\\Eloquent\\Collection'),
            EnvelopeBranchController::class.'::digestPages' => TraceScript::forChain('$q->cursorPaginate(15)', 'Illuminate\\Database\\Eloquent\\Builder'),
            EnvelopeBranchController::class.'::listed' => $plainWalk('listed', ListedCollection::class, 'users', 'Illuminate\\Database\\Eloquent\\Collection'),
            EnvelopeBranchController::class.'::gazetteListed' => TraceScript::forChain('$q->paginateList(15)', 'Illuminate\\Database\\Eloquent\\Builder'),
        ],
    );

    $this->only = static fn (string ...$methods): Closure => static function (Router $router) use ($methods): void {
        foreach ($methods as $method) {
            $router->get('api/zz-envelopes/'.$method, [EnvelopeBranchController::class, $method]);
        }
    };
    $this->methods = ['gazette', 'gazettePages', 'gazetteBuilt', 'digest', 'digestPages', 'listed'];
    $this->routes = ($this->only)(...$this->methods);

    $this->body = static fn (array $document, string $method): array => $document['paths']['/api/zz-envelopes/'.$method]['get']['responses']['200']['content']['application/json']['schema'];

    // The document's schema with every reference inlined, as a JSON value a validator reads.
    $inline = static function (mixed $node, array $document, ?string $key = null) use (&$inline): mixed {
        if (! is_array($node)) {
            return $node;
        }
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            return $inline($document['components']['schemas'][substr($node['$ref'], strlen('#/components/schemas/'))], $document);
        }
        if ($node === [] && ! in_array($key, ['required', 'enum'], true)) {
            return new stdClass;
        }
        $out = [];
        foreach ($node as $k => $v) {
            $out[$k] = $inline($v, $document, is_string($k) ? $k : $key);
        }

        return array_is_list($out) && in_array($key, ['required', 'enum', 'type', 'examples', 'anyOf', 'allOf', 'oneOf'], true) ? $out : (object) $out;
    };
    $this->inline = $inline;
});

it('emits each envelope with the with() members its own branch returns, byte-identical to its golden', function (): void {
    $result = localityBuild($this->routes, $this->engine);

    assertGolden('collection-envelope.uir.json', (new UirEmitter)->emit($result->document));

    $document = $result->document->toArray();
    $body = fn (string $method): array => ($this->body)($document, $method);

    // A plain list takes the non-paginator branch on every request, so the meta it adds is always sent.
    expect($body('gazette')['required'])->toBe(['data', 'meta'])
        ->and($body('gazette')['properties']['meta'])->toBe(['type' => 'object']);

    // A page takes the branch handing back the framework's members, which adds none: the page alone.
    expect($body('gazettePages')['$ref'] ?? null)->toBe('#/components/schemas/GazetteResourcePage')
        ->and($body('gazettePages'))->not->toHaveKeys(['allOf', 'properties']);

    // A page no terminal names is not proven plain, so every branch still counts and meta may be absent.
    expect($body('gazetteBuilt')['required'])->toBe(['data'])
        ->and(array_keys($body('gazetteBuilt')['properties']))->toBe(['data', 'meta']);

    // The plain branch itself sends meta on some requests only, so it stays optional, and only its shape is published.
    expect($body('digest')['required'])->toBe(['data'])
        ->and(array_keys($body('digest')['properties']['meta']['properties']))->toBe(['count']);

    // The paginator branch's meta extends the page's on every page, and only that branch's keys are claimed.
    expect($body('digestPages')['allOf'][0])->toBe(['$ref' => '#/components/schemas/DigestResourceCursorPage'])
        ->and($body('digestPages')['allOf'][1]['required'])->toBe(['meta'])
        ->and(array_keys($body('digestPages')['allOf'][1]['properties']['meta']['properties']))->toBe(['source']);

    // An unconditional with() publishes what it did before.
    expect($body('listed')['required'])->toBe(['data', 'meta', 'api_version']);
});

it('publishes a body each envelope Laravel actually sends validates against, and one missing the member does not', function (): void {
    $document = emittedArray(localityBuild($this->routes, $this->engine));
    $schema = fn (string $method): mixed => ($this->inline)(($this->body)($document, $method), $document);
    $sent = static fn (object $collection): mixed => json_decode((string) $collection->toResponse(Request::create('/'))->getContent());
    $validates = static fn (mixed $value, mixed $schema): bool => (new Validator)->validate($value, $schema)->isValid();
    $item = (object) ['name' => 'widget'];

    $plain = $sent(new GazetteCollection(collect([$item]), GazetteResource::class));
    $page = $sent(new GazetteCollection(new LengthAwarePaginator([$item], 1, 15), GazetteResource::class));
    $cursorPage = $sent(new DigestCollection(new CursorPaginator([$item], 15), DigestResource::class));

    expect($plain->meta)->toEqual(new stdClass)
        ->and($validates($plain, $schema('gazette')))->toBeTrue()
        ->and($validates($page, $schema('gazettePages')))->toBeTrue()
        ->and($cursorPage->meta->source)->toBe('digest')
        ->and($validates($cursorPage, $schema('digestPages')))->toBeTrue();

    // The required member is a claim the schema enforces: a plain list without it is refused.
    unset($plain->meta);
    expect($validates($plain, $schema('gazette')))->toBeFalse();
});

it('keys each envelope on the collection whose with() it reads, and a warm build equals a cold one', function (): void {
    $dir = fragmentCacheDir('collection-envelope');

    $cold = localityBuild($this->routes, $this->engine);
    $warm = localityBuild($this->routes, $this->engine, $counting);

    $fixtures = dirname(__DIR__, 2).'/Fixtures/ApiResources';
    expect($counting)->toBeInstanceOf(CountingTypeEngine::class)
        ->and($counting->analyzeCount)->toBe(0)
        ->and((new UirEmitter)->emit($warm->document))->toBe((new UirEmitter)->emit($cold->document))
        ->and($warm->diagnostics)->toEqual($cold->diagnostics)
        ->and(fragmentEntries($dir)['get /api/zz-envelopes/gazette']['dependencies'])->toContain($fixtures.'/GazetteCollection.php')
        ->and(fragmentEntries($dir)['get /api/zz-envelopes/gazettePages']['dependencies'])->toContain($fixtures.'/GazetteCollection.php');
});

it('emits the same bytes whichever order the routes are registered in', function (): void {
    $forward = (new UirEmitter)->emit(localityBuild($this->routes, $this->engine)->document);
    $reversed = (new UirEmitter)->emit(localityBuild(($this->only)(...array_reverse($this->methods)), $this->engine)->document);

    expect($reversed)->toBe($forward);
});

it('publishes each route and the components it reaches as that route built alone does', function (): void {
    $all = emittedArray(localityBuild($this->routes, $this->engine));

    $checked = 0;
    foreach ($this->methods as $method) {
        $alone = emittedArray(localityBuild(($this->only)($method), $this->engine));
        $path = '/api/zz-envelopes/'.$method;

        expect($all['paths'][$path])->toBe($alone['paths'][$path]);
        foreach ($alone['components']['schemas'] as $name => $schema) {
            expect($all['components']['schemas'][$name] ?? null)->toBe($schema);
            $checked++;
        }
    }

    // A scan that compared nothing would pass forever.
    expect($checked)->toBeGreaterThan(count($this->methods));
});

it('reads with() whole for a page an application\'s own terminal builds, whose paginator class it cannot know', function (): void {
    app()->forgetScopedInstances();
    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    ($this->only)('gazetteListed', 'gazettePages')($router);
    app()->instance(TypeEngine::class, ($this->engine)());

    $document = emittedArray(generateDocument(static function (array $raw): array {
        $raw['integrations']['query_builder']['pagination_terminals'] = ['paginateList'];

        return $raw;
    }));
    $body = ($this->body)($document, 'gazetteListed');

    // Laravel's paginate() builds a LengthAwarePaginator, whose branch adds nothing: the page alone.
    expect(($this->body)($document, 'gazettePages')['$ref'] ?? null)->toBe('#/components/schemas/GazetteResourcePage')
        ->and(($this->body)($document, 'gazettePages'))->not->toHaveKeys(['allOf', 'properties']);

    // paginateList may build any class, so both branches count and the plain branch's meta may be sent.
    expect($body['allOf'][0])->toBe(['$ref' => '#/components/schemas/GazetteResourcePage'])
        ->and(array_keys($body['allOf'][1]['properties']))->toBe(['meta'])
        ->and($body['allOf'][1])->not->toHaveKey('required');
});
