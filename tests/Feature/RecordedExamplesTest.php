<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Examples\ExampleRecording;
use Docuccino\Core\Examples\RecordedExample;
use Docuccino\Core\Examples\RecordingStore;
use Docuccino\Laravel\Tests\Fixtures\SharedErrors\ErrorsController;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Workbench\App\Http\Controllers\ExamplesController;

/**
 * The consuming half, through a real build of the workbench: a committed file becomes a documented
 * example, an authored `#[Example]` is never displaced by one, the artifact does not churn when a
 * re-recording only moved the values, and the build goes on executing nothing at all.
 */
beforeEach(function (): void {
    $this->recordings = base_path('docs/recordings-'.getmypid().'-'.bin2hex(random_bytes(6)));
    mkdir($this->recordings, 0777, true);
});

afterEach(function (): void {
    foreach (glob($this->recordings.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->recordings);
});

/**
 * @return array<string, mixed>
 */
function recordedDocument(string $recordings): array
{
    return stubDocumentArray(static function (array $raw) use ($recordings): array {
        $raw['examples'] = ['recordings' => $recordings];

        return $raw;
    });
}

function formsOperationId(): string
{
    $id = stubDocumentArray()['paths']['/api/forms']['get']['x-docuccino']['id'] ?? null;

    expect($id)->toBeString();

    /** @var string $id */
    return $id;
}

function writeFormsRecording(string $recordings, mixed $body, string $status = '200', ?string $operationId = null): void
{
    (new RecordingStore($recordings))->put(ExampleRecording::of(
        $operationId ?? formsOperationId(),
        'GET /api/forms',
        [RecordedExample::of($status, 'application/json', $body)],
    ));
}

/**
 * @return list<string>
 */
function recordedDiagnosticCodes(string $recordings): array
{
    bindStubEngine();

    $result = generateDocument(static function (array $raw) use ($recordings): array {
        $raw['examples'] = ['recordings' => $recordings];

        return $raw;
    });

    return array_values(array_map(
        static fn (Diagnostic $d): string => $d->code,
        array_filter($result->diagnostics, static fn (Diagnostic $d): bool => str_starts_with($d->code, 'examples.')),
    ));
}

it('publishes a recorded body as the example beside the documented schema', function (): void {
    writeFormsRecording($this->recordings, ['data' => [['id' => 1, 'title' => 'Intake']]]);

    $media = recordedDocument($this->recordings)['paths']['/api/forms']['get']['responses']['200']['content']['application/json'];

    expect($media['example'])->toBe(['data' => [['id' => 1, 'title' => 'Intake']]])
        ->and($media)->toHaveKey('schema');
});

it('publishes nothing when the document names no recordings directory', function (): void {
    writeFormsRecording($this->recordings, ['data' => []]);

    $media = stubDocumentArray()['paths']['/api/forms']['get']['responses']['200']['content']['application/json'];

    expect($media)->not->toHaveKey('example');
});

it('emits the same bytes when a re-recording only moved the values', function (): void {
    writeFormsRecording($this->recordings, ['data' => [['id' => 1, 'title' => 'Intake']]]);
    bindStubEngine();
    $first = (new UirEmitter)->emit(generateDocument(fn (array $raw): array => $raw + ['examples' => ['recordings' => $this->recordings]])->document);

    // What a second run of the same suite writes: the recorder keeps the committed body while its
    // shape is unchanged, so this is a no-op on the file and therefore a no-op on the artifact.
    $store = new RecordingStore($this->recordings);
    $recording = $store->read(formsOperationId());
    expect($recording)->not->toBeNull();
    $store->put($recording->with(RecordedExample::of('200', 'application/json', ['data' => [['id' => 9001, 'title' => 'Later']]])));

    bindStubEngine();
    $second = (new UirEmitter)->emit(generateDocument(fn (array $raw): array => $raw + ['examples' => ['recordings' => $this->recordings]])->document);

    expect($second)->toBe($first)
        ->and($first)->toContain('"title": "Intake"');
});

it('emits the same bytes twice from the same recording', function (): void {
    writeFormsRecording($this->recordings, ['data' => [['id' => 1, 'title' => 'Intake']]]);

    bindStubEngine();
    $first = (new UirEmitter)->emit(generateDocument(fn (array $raw): array => $raw + ['examples' => ['recordings' => $this->recordings]])->document);
    bindStubEngine();
    $second = (new UirEmitter)->emit(generateDocument(fn (array $raw): array => $raw + ['examples' => ['recordings' => $this->recordings]])->document);

    expect($second)->toBe($first);
});

it('opens no database and dispatches no route while it reads one', function (): void {
    writeFormsRecording($this->recordings, ['data' => [['id' => 1, 'title' => 'Intake']]]);

    $ran = [];
    Event::listen(QueryExecuted::class, function () use (&$ran): void {
        $ran[] = 'query';
    });
    Event::listen(RouteMatched::class, function () use (&$ran): void {
        $ran[] = 'route';
    });

    // Any query at all would have to go through a connection that cannot be opened.
    config()->set('database.default', 'docuccino-nonexistent');

    $media = recordedDocument($this->recordings)['paths']['/api/forms']['get']['responses']['200']['content']['application/json'];

    expect($media['example'])->toBe(['data' => [['id' => 1, 'title' => 'Intake']]])
        ->and($ran)->toBe([]);
});

it('reports a recording for an operation the document no longer has', function (): void {
    writeFormsRecording($this->recordings, ['data' => []], operationId: 'op:v1:zzzzzzzz12345678');

    expect(recordedDiagnosticCodes($this->recordings))->toBe(['examples.recording-orphaned']);
});

it('reports, and refuses to publish, a recording that still holds a credential', function (): void {
    writeFormsRecording($this->recordings, ['data' => [], 'api_key' => 'live-secret-value']);

    $media = recordedDocument($this->recordings)['paths']['/api/forms']['get']['responses']['200']['content']['application/json'];

    expect($media)->not->toHaveKey('example')
        ->and(recordedDiagnosticCodes($this->recordings))->toBe(['examples.recording-unsafe']);
});

it('says a configured directory holds nothing yet', function (): void {
    expect(recordedDiagnosticCodes($this->recordings))->toBe(['examples.recordings-empty']);
});

it('reports the same thing on a warm build as on a cold one, and rebuilds when the recording changes', function (): void {
    writeFormsRecording($this->recordings, ['data' => [['id' => 1, 'title' => 'Intake']]]);

    $dir = fragmentCacheDir('recordings');

    try {
        $cold = recordedDocument($this->recordings);
        expect(glob($dir.'/*.json') ?: [])->not->toBeEmpty();

        $warm = recordedDocument($this->recordings);
        expect(graphDifferences($warm, $cold))->toBe([]);

        // Editing the committed file has to reach the document, or a re-recording would land warm.
        writeFormsRecording($this->recordings, ['data' => [['id' => 2, 'title' => 'Rewritten']]]);
        $rebuilt = recordedDocument($this->recordings);

        expect($rebuilt['paths']['/api/forms']['get']['responses']['200']['content']['application/json']['example'])
            ->toBe(['data' => [['id' => 2, 'title' => 'Rewritten']]]);
    } finally {
        removeFragmentCacheDir($dir);
    }
});

it('publishes a recording made before the file existed once it does', function (): void {
    $dir = fragmentCacheDir('recordings');

    try {
        $cold = recordedDocument($this->recordings);
        expect($cold['paths']['/api/forms']['get']['responses']['200']['content']['application/json'])
            ->not->toHaveKey('example');

        writeFormsRecording($this->recordings, ['data' => [['id' => 1, 'title' => 'Intake']]]);

        expect(recordedDocument($this->recordings)['paths']['/api/forms']['get']['responses']['200']['content']['application/json']['example'])
            ->toBe(['data' => [['id' => 1, 'title' => 'Intake']]]);
    } finally {
        removeFragmentCacheDir($dir);
    }
});

/**
 * Register one `ExamplesController` action and hand back the id the build files its recording under.
 * These actions carry real `#[Example]` declarations, which is what makes them the precedence question
 * asked of a whole build rather than of a hand-built draft.
 */
function recordedExampleRoute(string $action, string $uri): string
{
    /** @var Router $router */
    $router = app('router');
    $router->get($uri, [ExamplesController::class, $action]);

    $id = stubDocumentArray()['paths']['/'.$uri]['get']['x-docuccino']['id'] ?? null;

    expect($id)->toBeString();

    /** @var string $id */
    return $id;
}

/**
 * @param  list<RecordedExample>  $responses
 */
function writeRecording(string $recordings, string $operationId, string $endpoint, array $responses): void
{
    (new RecordingStore($recordings))->put(ExampleRecording::of($operationId, $endpoint, $responses));
}

it('publishes the example an author wrote, never the one a suite recorded', function (): void {
    $id = recordedExampleRoute('unnamed', 'api/examples-unnamed');
    writeRecording($this->recordings, $id, 'GET /api/examples-unnamed', [
        RecordedExample::of('200', 'application/json', ['id' => 999, 'name' => 'Recorded', 'status' => 'draft']),
    ]);

    $media = recordedDocument($this->recordings)['paths']['/api/examples-unnamed']['get']['responses']['200']['content']['application/json'];

    expect($media['example'])->toBe(['id' => 7, 'name' => 'Cog', 'status' => 'draft'])
        ->and($media)->not->toHaveKey('examples');
});

it('puts nothing of its own into an authored examples map', function (): void {
    $id = recordedExampleRoute('show', 'api/examples/{widget}');
    writeRecording($this->recordings, $id, 'GET /api/examples/{widget}', [
        RecordedExample::of('200', 'application/json', ['id' => 999, 'name' => 'Recorded', 'status' => 'draft']),
        RecordedExample::of('404', 'application/json', ['id' => 998, 'name' => 'Recorded', 'status' => 'draft']),
    ]);

    $responses = recordedDocument($this->recordings)['paths']['/api/examples/{widget}']['get']['responses'];

    // The author's names and only the author's — and no `example` beside them, which OpenAPI forbids
    // and which is the only slot a recording could otherwise have taken here.
    expect(array_keys($responses['200']['content']['application/json']['examples']))->toBe(['discontinued', 'stocked'])
        ->and($responses['200']['content']['application/json'])->not->toHaveKey('example')
        ->and(array_keys($responses['404']['content']['application/json']['examples']))->toBe(['missing'])
        ->and($responses['404']['content']['application/json'])->not->toHaveKey('example');
});

it('publishes where an author named no example, on an action where they named one elsewhere', function (): void {
    $id = recordedExampleRoute('search', 'api/examples-search');
    writeRecording($this->recordings, $id, 'GET /api/examples-search', [
        RecordedExample::of('200', 'application/json', ['id' => 3, 'name' => 'Sprocket', 'status' => 'published']),
    ]);

    $operation = recordedDocument($this->recordings)['paths']['/api/examples-search']['get'];

    // The declarations on this action are on the `q` parameter; the response nobody spoke for takes
    // the recording, so stepping aside is per node rather than per operation.
    expect($operation['responses']['200']['content']['application/json']['example'])
        ->toBe(['id' => 3, 'name' => 'Sprocket', 'status' => 'published'])
        ->and(array_keys($operation['parameters'][0]['examples']))->toBe(['by-name', 'by-sku']);
});

it('holds a recorded example to the schema beside it', function (mixed $body, bool $ok): void {
    $id = recordedExampleRoute('search', 'api/examples-search');
    writeRecording($this->recordings, $id, 'GET /api/examples-search', [
        RecordedExample::of('200', 'application/json', $body),
    ]);

    bindStubEngine();
    $document = generateDocument(fn (array $raw): array => $raw + ['examples' => ['recordings' => $this->recordings]])->document->toArray();
    $report = (new ExampleAudit(ContractIndex::fromArray($document)))->run();

    expect($report->ok())->toBe($ok)
        ->and($report->checked)->toBeGreaterThan(0);

    if (! $ok) {
        expect($report->findings[0]->pointer)->toBe('/paths/~1api~1examples-search/get/responses/200/content/application~1json/example');
    }
})->with([
    'one that fits' => [['id' => 3, 'name' => 'Sprocket', 'status' => 'published'], true],
    'one the code has moved under' => [['id' => 'INV-3', 'name' => 'Sprocket', 'status' => 'published'], false],
]);

it('leaves a route it recorded nothing for exactly where it was', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-denied', [ErrorsController::class, 'denied']);
    $router->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);

    $before = recordedDocument($this->recordings);

    writeRecording($this->recordings, $before['paths']['/api/zz-denied']['get']['x-docuccino']['id'], 'GET /api/zz-denied', [
        RecordedExample::of('403', 'application/json', ['code' => 'forbidden']),
    ]);

    $after = recordedDocument($this->recordings);

    // The recording is the media type's, never the schema's: written into the schema it would change
    // what this route's 403 IS, and the shared body its neighbour also points at would come apart. The
    // two share a response, so the illustration publishes on the component beside the shape it shows.
    expect(resolveResponse($after, $after['paths']['/api/zz-denied']['get']['responses']['403'])['content']['application/json']['example'])
        ->toBe(['code' => 'forbidden'])
        ->and($after['paths']['/api/zz-denied-again'])->toBe($before['paths']['/api/zz-denied-again'])
        ->and($after['components']['schemas']['Error403'])->toBe($before['components']['schemas']['Error403']);
});

it('publishes named recordings as the examples map of the media type they illustrate', function (): void {
    writeRecording($this->recordings, formsOperationId(), 'GET /api/forms', [
        RecordedExample::of('200', 'application/json', [], 'no-forms'),
        RecordedExample::of('200', 'application/json', [['id' => 1, 'title' => 'Intake']], 'one-form'),
    ]);

    $media = recordedDocument($this->recordings)['paths']['/api/forms']['get']['responses']['200']['content']['application/json'];

    expect($media['examples'])->toBe([
        'no-forms' => ['value' => []],
        'one-form' => ['value' => [['id' => 1, 'title' => 'Intake']]],
    ])->and($media)->not->toHaveKey('example');
});

it('emits a recorded examples map through every OpenAPI version, and validates as UIR', function (): void {
    writeRecording($this->recordings, formsOperationId(), 'GET /api/forms', [
        RecordedExample::of('200', 'application/json', [], 'no-forms'),
        RecordedExample::of('200', 'application/json', [['id' => 1, 'title' => 'Intake']], 'one-form'),
    ]);

    bindStubEngine();
    $result = generateDocument(fn (array $raw): array => $raw + ['examples' => ['recordings' => $this->recordings]]);

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $result->diagnostics))->not->toContain('document.schema-invalid');

    foreach ([new OpenApi32Emitter, new OpenApi31DownlevelEmitter, new OpenApi30DownlevelEmitter] as $emitter) {
        $emitted = $emitter->emit($result->document);
        $media = json_decode($emitted, true)['paths']['/api/forms']['get']['responses']['200']['content']['application/json'];

        // OpenAPI carries one or the other on a media type, in every version this emits.
        expect(array_keys($media['examples']))->toBe(['no-forms', 'one-form'])
            ->and($media)->not->toHaveKey('example');
    }
});

it('holds every named recorded example to the schema beside it', function (): void {
    $id = recordedExampleRoute('search', 'api/examples-search');
    writeRecording($this->recordings, $id, 'GET /api/examples-search', [
        RecordedExample::of('200', 'application/json', ['id' => 3, 'name' => 'Sprocket', 'status' => 'published'], 'fits'),
        RecordedExample::of('200', 'application/json', ['id' => 'INV-3', 'name' => 'Sprocket', 'status' => 'published'], 'moved'),
    ]);

    bindStubEngine();
    $document = generateDocument(fn (array $raw): array => $raw + ['examples' => ['recordings' => $this->recordings]])->document->toArray();
    $report = (new ExampleAudit(ContractIndex::fromArray($document)))->run();

    expect($report->ok())->toBeFalse()
        ->and($report->findings[0]->pointer)
        ->toBe('/paths/~1api~1examples-search/get/responses/200/content/application~1json/examples/moved/value');
});

it('joins the map an author curated, under the name the test chose and never over theirs', function (): void {
    $id = recordedExampleRoute('show', 'api/examples/{widget}');
    writeRecording($this->recordings, $id, 'GET /api/examples/{widget}', [
        RecordedExample::of('200', 'application/json', ['id' => 9, 'name' => 'Recorded', 'status' => 'draft'], 'as-tested'),
        RecordedExample::of('200', 'application/json', ['id' => 8, 'name' => 'Overruled', 'status' => 'draft'], 'stocked'),
        RecordedExample::of('404', 'application/json', ['id' => 7, 'name' => 'Gone', 'status' => 'draft'], 'as-tested'),
    ]);

    $responses = recordedDocument($this->recordings)['paths']['/api/examples/{widget}']['get']['responses'];
    $ok = $responses['200']['content']['application/json'];

    expect(array_keys($ok['examples']))->toBe(['as-tested', 'discontinued', 'stocked'])
        ->and($ok['examples']['stocked']['value'])->not->toBe(['id' => 8, 'name' => 'Overruled', 'status' => 'draft'])
        ->and($ok)->not->toHaveKey('example')
        // The 404 is a status the shared-error pass groups, so its names go nowhere at all — and the
        // author's map is what publishes there whether or not anything was recorded for it.
        ->and(array_keys($responses['404']['content']['application/json']['examples']))->toBe(['missing'])
        ->and($responses['404']['content']['application/json'])->not->toHaveKey('example');
});

it('leaves a neighbour exactly where it was when a name is recorded on a shared error', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-denied', [ErrorsController::class, 'denied']);
    $router->get('api/zz-denied-again', [ErrorsController::class, 'deniedAgain']);

    $before = recordedDocument($this->recordings);

    writeRecording($this->recordings, $before['paths']['/api/zz-denied']['get']['x-docuccino']['id'], 'GET /api/zz-denied', [
        RecordedExample::of('403', 'application/json', ['code' => 'expired'], 'expired'),
        RecordedExample::of('403', 'application/json', ['code' => 'forbidden'], 'missing'),
    ]);

    $after = recordedDocument($this->recordings);

    // A recorded name is not an authored one, so it publishes as the singular example this route's arm
    // carries into the response it shares.
    $media = resolveResponse($after, $after['paths']['/api/zz-denied']['get']['responses']['403'])['content']['application/json'];

    // The neighbour recorded nothing, so what a consumer READS for its 403 — the component it points at,
    // its own words, its shape — is what it read before, with one sanctioned widening: the two state one
    // response, so the illustration published on it is offered by both. That is the whole of the change,
    // which is why it is stated as one member rather than left to a schema comparison.
    $neighbour = resolveResponse($after, $after['paths']['/api/zz-denied-again']['get']['responses']['403']);
    $before403 = resolveResponse($before, $before['paths']['/api/zz-denied-again']['get']['responses']['403']);

    $widened = $neighbour;
    unset($widened['content']['application/json']['example']);

    expect($media['example'])->toBe(['code' => 'expired'])
        ->and($media)->not->toHaveKey('examples')
        ->and($after['paths']['/api/zz-denied-again'])->toBe($before['paths']['/api/zz-denied-again'])
        ->and($neighbour['content']['application/json']['example'])->toBe(['code' => 'expired'])
        ->and($before403['content']['application/json'])->not->toHaveKey('example')
        ->and($widened)->toBe($before403);
});

it('says so where a recorded name went nowhere', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/zz-denied', [ErrorsController::class, 'denied']);

    $id = recordedDocument($this->recordings)['paths']['/api/zz-denied']['get']['x-docuccino']['id'];
    writeRecording($this->recordings, $id, 'GET /api/zz-denied', [
        RecordedExample::of('403', 'application/json', ['code' => 'forbidden'], 'missing'),
    ]);

    expect(recordedDiagnosticCodes($this->recordings))->toBe(['examples.recording-name-unpublished']);
});

it('says nothing about a name the document publishes', function (): void {
    writeRecording($this->recordings, formsOperationId(), 'GET /api/forms', [
        RecordedExample::of('200', 'application/json', [], 'no-forms'),
    ]);

    expect(recordedDiagnosticCodes($this->recordings))->toBe([]);
});
