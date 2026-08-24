<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Tests\Support\CountingTypeEngine;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\ExampleDegradationsController;
use Workbench\App\Http\Controllers\ExamplesController;

/**
 * `#[Example]` end to end: several named examples on one node, the payload loaded from a file, and
 * every way a declaration can fail to describe one. Examples are the part of a document a reader
 * copies, so the degradations matter as much as the happy path — a dropped example must always leave
 * a diagnostic behind, and never a half-written map.
 */
const EXAMPLE_FILES = [
    'docuccino-example.json' => '{"id": 3, "name": "From JSON", "status": "draft"}',
    'docuccino-example.yaml' => "id: 4\nname: From YAML\nstatus: draft\n",
    'docuccino-example.yml' => "id: 5\nname: From YML\nstatus: draft\n",
    'docuccino-example-broken.json' => '{"id": ',
    'docuccino-example-broken.yaml' => "root:\n\t- indented with a tab\n",
    'docuccino-example.txt' => 'plain text',
    'docuccino-example-null.json' => 'null',
    'docuccino-example-nan.yaml' => "ratio: .nan\n",
];

beforeEach(function (): void {
    foreach (EXAMPLE_FILES as $name => $contents) {
        file_put_contents(base_path($name), $contents);
    }

    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

afterEach(function (): void {
    foreach (array_keys(EXAMPLE_FILES) as $name) {
        @unlink(base_path($name));
    }

    removeFragmentCacheDirs('examples');
});

/** Register one `ExamplesController` action and document it. */
function exampleRoutes(callable $routes): array
{
    /** @var Router $router */
    $router = app('router');
    $routes($router);

    return generateDocument()->document->toArray()['paths'];
}

/** The `#[Example]` diagnostics one degradation action raises, as `code => message` pairs. */
function degradationDiagnostics(string $action): array
{
    /** @var Router $router */
    $router = app('router');
    $router->get('api/degraded', [ExampleDegradationsController::class, $action]);

    $result = generateDocument();

    $found = [];
    foreach ($result->diagnostics as $diagnostic) {
        if (str_contains($diagnostic->code, 'example')) {
            $found[] = [$diagnostic->severity->value, $diagnostic->code, $diagnostic->message, $diagnostic->help];
        }
    }

    return [$found, $result->document->toArray()['paths']['/api/degraded']['get']];
}

it('collects several named examples into one map on the response they illustrate', function (): void {
    $paths = exampleRoutes(static fn (Router $router) => $router->get('api/examples/{widget}', [ExamplesController::class, 'show']));

    $media = $paths['/api/examples/{widget}']['get']['responses']['200']['content']['application/json'];

    expect($media['examples'])->toBe([
        'discontinued' => [
            'summary' => 'One that is no longer sold',
            'description' => 'Still readable, never orderable.',
            'value' => ['id' => 2, 'name' => 'Grommet', 'status' => 'archived'],
        ],
        'stocked' => [
            'summary' => 'A widget in stock',
            'value' => ['id' => 1, 'name' => 'Sprocket', 'status' => 'published'],
        ],
    ])
        // Declaration order is stocked-then-discontinued: the map is a function of the names, not of
        // the order they were met.
        ->and(array_keys($media['examples']))->toBe(['discontinued', 'stocked'])
        ->and($media)->not->toHaveKey('example');
});

it('sends a declaration to the status it names rather than to the success response', function (): void {
    $paths = exampleRoutes(static fn (Router $router) => $router->get('api/examples/{widget}', [ExamplesController::class, 'show']));

    $responses = $paths['/api/examples/{widget}']['get']['responses'];

    expect(array_keys($responses['404']['content']['application/json']['examples']))->toBe(['missing'])
        ->and($responses['200']['content']['application/json']['examples'])->not->toHaveKey('missing');
});

it('loads a payload out of every file format, and carries an externalValue as a reference', function (): void {
    $paths = exampleRoutes(static fn (Router $router) => $router->get('api/examples-file', [ExamplesController::class, 'fromFile']));

    $examples = $paths['/api/examples-file']['get']['responses']['200']['content']['application/json']['examples'];

    expect($examples['from-json'])->toBe(['summary' => 'Loaded from JSON', 'value' => ['id' => 3, 'name' => 'From JSON', 'status' => 'draft']])
        ->and($examples['from-yaml']['value'])->toBe(['id' => 4, 'name' => 'From YAML', 'status' => 'draft'])
        ->and($examples['from-yml']['value'])->toBe(['id' => 5, 'name' => 'From YML', 'status' => 'draft'])
        ->and($examples['elsewhere'])->toBe(['externalValue' => 'https://example.test/widgets/1.json']);
});

it('attaches named examples to a request body without disturbing the schema that was there', function (): void {
    $paths = exampleRoutes(static fn (Router $router) => $router->post('api/examples', [ExamplesController::class, 'store']));

    $media = $paths['/api/examples']['post']['requestBody']['content']['application/json'];

    expect(array_keys($media['examples']))->toBe(['bulk', 'minimal'])
        ->and($media['examples']['minimal'])->toBe(['summary' => 'Just the name', 'value' => ['name' => 'Sprocket']])
        ->and($media['schema']['properties'])->toHaveKeys(['name', 'quantity'])
        ->and($media['schema']['required'])->toBe(['name']);
});

it('attaches named examples to the parameter they name, wherever it lives', function (): void {
    $paths = exampleRoutes(static fn (Router $router) => $router->get('api/examples-search', [ExamplesController::class, 'search']));

    $parameter = paramsByName($paths['/api/examples-search']['get'])['q'];

    expect($parameter['examples'])->toBe([
        'by-name' => ['summary' => 'Search by name', 'value' => 'sprocket'],
        'by-sku' => ['value' => 'SKU-4711'],
    ])->and($parameter)->not->toHaveKey('example');
});

it('finds a named parameter in every location OpenAPI has', function (string $name, string $in, mixed $value): void {
    $paths = exampleRoutes(static fn (Router $router) => $router->get('api/examples-locations/{widget}', [ExamplesController::class, 'everyLocation']));

    $parameter = paramsByName($paths['/api/examples-locations/{widget}']['get'])[$name];

    expect($parameter['in'])->toBe($in)
        ->and(array_values($parameter['examples'])[0]['value'])->toBe($value);
})->with([
    'path' => ['widget', 'path', '42'],
    'query' => ['q', 'query', 'sprocket'],
    'header' => ['X-Tenant', 'header', 'acme'],
    'cookie' => ['session', 'cookie', 'abc123'],
]);

it('pins a nameless declaration as the singular example, beside the schema', function (): void {
    $paths = exampleRoutes(static fn (Router $router) => $router->get('api/examples-unnamed', [ExamplesController::class, 'unnamed']));

    $media = $paths['/api/examples-unnamed']['get']['responses']['200']['content']['application/json'];

    expect($media['example'])->toBe(['id' => 7, 'name' => 'Cog', 'status' => 'draft'])
        ->and($media)->not->toHaveKey('examples');
});

it('diagnoses every way a declaration can fail to describe an example, and publishes none of them', function (string $action, string $code, string $severity, string $fragment): void {
    [$diagnostics, $operation] = degradationDiagnostics($action);

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0][0])->toBe($severity)
        ->and($diagnostics[0][1])->toBe($code)
        ->and($diagnostics[0][2])->toContain($fragment)
        // Every one of them names something to do about it.
        ->and($diagnostics[0][3])->not->toBeNull()
        ->and($diagnostics[0][3])->not->toBe('');

    // Nothing half-written reached the document.
    $media = $operation['responses']['200']['content']['application/json'];
    expect($media)->not->toHaveKey('examples')->and($media)->not->toHaveKey('example');
})->with([
    'no value at all' => ['noValue', 'attribute.example-unusable', 'warning', 'carries no value'],
    'two values' => ['twoValues', 'attribute.example-unusable', 'warning', 'carries more than one value'],
    'two targets' => ['twoTargets', 'attribute.example-unusable', 'warning', 'more than one thing to illustrate'],
    'a summary with no name to carry it' => ['unnamedSummary', 'attribute.example-unusable', 'warning', 'carries `summary:` but no `name:`'],
    'a status the operation has not got' => ['unknownStatus', 'attribute.example-target-missing', 'warning', 'no 418 response'],
    'a media type the response has not got' => ['unknownMediaType', 'attribute.example-target-missing', 'warning', 'no "application/xml" content'],
    'a parameter that is not documented' => ['unknownParameter', 'attribute.example-target-missing', 'warning', 'no parameter named "nope"'],
    'a request body that does not exist' => ['noRequestBody', 'attribute.example-target-missing', 'warning', 'documents no request body'],
    'a path that escapes the app' => ['escapingFile', 'example-file.escapes-base-path', 'error', 'escapes the application base path'],
    'a file that is not there' => ['missingFile', 'example-file.missing', 'warning', 'could not be read'],
    'malformed JSON' => ['malformedJson', 'example-file.invalid', 'warning', 'docuccino-example-broken.json'],
    'malformed YAML' => ['malformedYaml', 'example-file.invalid', 'warning', 'docuccino-example-broken.yaml'],
    'a format examples are not read from' => ['unsupportedFormat', 'example-file.invalid', 'warning', 'is not a format examples are read from'],
    'a file holding nothing' => ['emptyFile', 'example-file.invalid', 'warning', 'it decodes to null'],
    // Both halves of "parses, but no JSON document can hold it": YAML spells `.nan`, and an attribute
    // argument may be `NAN` outright. Either used to reach the canonical writer, which threw naming
    // neither the route nor the declaration and took the build with it.
    'a file holding a value JSON cannot express' => ['unpublishableFile', 'example-file.invalid', 'warning', 'non-finite floats'],
    'a value JSON cannot express' => ['nonFiniteValue', 'attribute.example-unusable', 'warning', 'no JSON document can hold'],
]);

it('keeps the first of two declarations sharing a name, and says the second went', function (): void {
    [$diagnostics, $operation] = degradationDiagnostics('duplicateName');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0][1])->toBe('attribute.example-duplicate-name')
        ->and($diagnostics[0][2])->toContain('both named "twice"')
        ->and($operation['responses']['200']['content']['application/json']['examples'])
        ->toBe(['twice' => ['value' => ['id' => 1, 'name' => 'Cog', 'status' => 'draft']]]);
});

it('keeps the named map when a nameless declaration shares the node, and says the bare one went', function (): void {
    [$diagnostics, $operation] = degradationDiagnostics('namedAndUnnamed');

    $media = $operation['responses']['200']['content']['application/json'];

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0][1])->toBe('attribute.example-unusable')
        ->and($diagnostics[0][2])->toContain('never both')
        ->and($media['examples'])->toBe(['named' => ['value' => ['id' => 1, 'name' => 'Cog', 'status' => 'draft']]])
        ->and($media)->not->toHaveKey('example');
});

it('reports an example that lies about its own schema, on the build that publishes it', function (): void {
    // No attribute rule can catch this: the declaration is well formed and the target exists. The audit
    // holds every published example to the schema beside it, and it runs on every build — an example is
    // the part of a document a consumer copies, so hearing about it only in an opt-in test is too late.
    [$diagnostics] = degradationDiagnostics('mismatchedValue');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0][0])->toBe('warning')
        ->and($diagnostics[0][1])->toBe('lint.example-mismatch')
        ->and($diagnostics[0][2])->toContain('wrong-shape')
        ->and($diagnostics[0][2])->toContain('The required properties (status) are missing')
        ->and($diagnostics[0][3])->not->toBeNull();

    // And the same finding through the audit's own entry point, which the contract assertion uses.
    $document = generateDocument()->document->toArray();
    $report = (new ExampleAudit(ContractIndex::fromArray($document)))->run();

    expect($report->ok())->toBeFalse()
        ->and($report->findings[0]->pointer)->toContain('wrong-shape');
});

it('says nothing about an example that satisfies the schema beside it', function (): void {
    // The other half of the lint: it has to be silent on a document whose examples are all right, or
    // it is a channel nobody reads. Every published example on these routes is a conforming body.
    [$diagnostics] = degradationDiagnostics('duplicateName');

    expect(array_column($diagnostics, 1))->not->toContain('lint.example-mismatch');
});

it('holds the examples it publishes to the schemas beside them', function (): void {
    $document = exampleRoutes(function (Router $router): void {
        $router->get('api/examples/{widget}', [ExamplesController::class, 'show']);
        $router->get('api/examples-file', [ExamplesController::class, 'fromFile']);
        $router->post('api/examples', [ExamplesController::class, 'store']);
        $router->get('api/examples-search', [ExamplesController::class, 'search']);
        $router->get('api/examples-unnamed', [ExamplesController::class, 'unnamed']);
    });

    expect($document)->not->toBeEmpty();

    $report = (new ExampleAudit(ContractIndex::fromArray(generateDocument()->document->toArray())))->run();

    expect($report->checked)->toBeGreaterThan(8)
        ->and($report->findings)->toBe([]);
});

it('carries named examples through UIR validation and every OpenAPI version', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/examples/{widget}', [ExamplesController::class, 'show']);
    $router->post('api/examples', [ExamplesController::class, 'store']);
    $router->get('api/examples-search', [ExamplesController::class, 'search']);

    $result = generateDocument();

    expect(array_map(static fn ($d): string => $d->code, $result->diagnostics))->not->toContain('document.schema-invalid');

    foreach ([new OpenApi32Emitter, new OpenApi31DownlevelEmitter, new OpenApi30DownlevelEmitter] as $emitter) {
        $emitted = $emitter->emit($result->document);

        expect($emitted)->toContain('"discontinued"')
            ->and($emitted)->toContain('"minimal"')
            ->and($emitted)->toContain('"by-sku"');
    }
});

it('round-trips a document carrying named examples through the model unchanged', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/examples/{widget}', [ExamplesController::class, 'show']);
    $router->get('api/examples-file', [ExamplesController::class, 'fromFile']);

    $document = generateDocument()->document->toArray();

    expect(UirDocument::fromArray($document)->toArray())->toEqual($document);
});

it('replays examples and their diagnostics identically on a warm build and a cold one', function (): void {
    $result = assertWarmEqualsCold(
        static fn (Router $router) => $router->get('api/examples-file', [ExamplesController::class, 'fromFile']),
        function (Router $router): void {
            $router->get('api/examples-file', [ExamplesController::class, 'fromFile']);
            $router->get('api/degraded', [ExampleDegradationsController::class, 'missingFile']);
        },
    );

    // A warm build saying less than a cold one is the silent degradation this exists to catch, and a
    // dropped example is exactly the kind of thing that would go quiet.
    expect(diagnosticsCoded($result->diagnostics, 'example-file.missing'))->toHaveCount(1)
        ->and($result->document->toArray()['paths']['/api/examples-file']['get']['responses']['200']['content']['application/json']['examples'])
        ->toHaveKey('from-json');
});

it('rebuilds a route when the file its example is read from changes', function (): void {
    fragmentCacheDir('examples');

    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    /** @var Router $router */
    $router = app('router');
    $router->get('api/examples-file', [ExamplesController::class, 'fromFile']);

    $cold = generateDocument()->document->toArray();
    expect($cold['paths']['/api/examples-file']['get']['responses']['200']['content']['application/json']['examples']['from-json']['value']['name'])
        ->toBe('From JSON');

    file_put_contents(base_path('docuccino-example.json'), '{"id": 9, "name": "Edited", "status": "draft"}');

    $warm = generateDocument()->document->toArray();

    expect($warm['paths']['/api/examples-file']['get']['responses']['200']['content']['application/json']['examples']['from-json']['value'])
        ->toBe(['id' => 9, 'name' => 'Edited', 'status' => 'draft']);
});

it('rebuilds a route when a file its example could not be read from appears', function (): void {
    fragmentCacheDir('examples');

    $engine = new CountingTypeEngine(WorkbenchEngine::make());
    app()->instance(TypeEngine::class, $engine);

    /** @var Router $router */
    $router = app('router');
    $router->get('api/degraded', [ExampleDegradationsController::class, 'missingFile']);

    $cold = generateDocument();
    expect(diagnosticsCoded($cold->diagnostics, 'example-file.missing'))->toHaveCount(1);

    file_put_contents(base_path('docuccino-example-absent.json'), '{"id": 1, "name": "Now there", "status": "draft"}');

    try {
        $warm = generateDocument();

        expect(diagnosticsCoded($warm->diagnostics, 'example-file.missing'))->toBe([])
            ->and($warm->document->toArray()['paths']['/api/degraded']['get']['responses']['200']['content']['application/json']['examples']['absent']['value']['name'])
            ->toBe('Now there');
    } finally {
        @unlink(base_path('docuccino-example-absent.json'));
    }
});

it('leaves an unrelated operation byte-identical when a route with examples joins the document', function (): void {
    assertUnaffectedByUnrelatedRoute(
        static fn (Router $router) => $router->get('api/examples-unnamed', [ExamplesController::class, 'unnamed']),
        static fn (Router $router) => $router->get('api/examples/{widget}', [ExamplesController::class, 'show']),
        'GET /api/examples-unnamed',
    );
});

it('emits the same bytes for the same declarations, build after build', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/examples/{widget}', [ExamplesController::class, 'show']);
    $router->get('api/examples-file', [ExamplesController::class, 'fromFile']);
    $router->post('api/examples', [ExamplesController::class, 'store']);

    expect((new UirEmitter)->emit(generateDocument()->document))
        ->toBe((new UirEmitter)->emit(generateDocument()->document));
});
