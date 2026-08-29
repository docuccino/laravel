<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi32Emitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Versioning\ApiVersionTransformer;
use Docuccino\Laravel\Versioning\VersionChangeCollector;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * The version header is one declaration, published once and pointed at from every operation.
 *
 * Inline it was the document's largest repetition by a distance: four parallel arrays one member long
 * per version, plus a fixed sentence, restated on every operation — so the document grew by operations
 * × versions where nothing about the declaration varies with either. Hoisted it is flat in the version
 * count, which is what the emitted-once assertions below are really pinning.
 *
 * The routes and the `documents` bag are declared here rather than in `TestCase::defineRoutes()` and
 * the shipped config, for the reason {@see ApiVersionDocumentTest} states: the shipped route set is
 * enumerated verbatim in six byte-locked goldens and this suite must move none of them.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
    $router->get('api/versioned-forms/archived', [VersionedFormController::class, 'archived']);

    config()->set('docuccino.documents', versionedFormDocuments());
});

/**
 * Both workbench routes, so "every operation" is more than one operation. The bag's own filter names
 * the index route alone.
 *
 * @return array<string, mixed>
 */
function twoOperationVersion(array $raw): array
{
    $raw['routes'] = ['include' => ['api/versioned-forms', 'api/versioned-forms/archived']];

    return $raw;
}

it('publishes the header once and points every operation at it', function (): void {
    $document = generateDocument(twoOperationVersion(...), 'v2026-06-01')->document->toArray();

    $ref = '#/components/parameters/XApiVersion';

    expect(array_keys($document['components']['parameters']))->toBe(['XApiVersion'])
        ->and(parameterRefs($document))->toBe([$ref])
        ->and(parameterRefs($document, '/api/versioned-forms/archived'))->toBe([$ref])
        // The declaration itself, which now lives in exactly one place.
        ->and(versionHeaderComponent($document)['in'])->toBe('header')
        ->and(versionHeaderComponent($document)['required'])->toBeFalse()
        ->and(versionHeaderComponent($document)['schema']['enum'])->toBe(['2026-06-01', '2026-09-01']);
});

/*
 * The defect this hoist exists for, stated as an invariant rather than as a byte count: the enum and
 * its three decoration arrays are published ONCE however many operations reach them, so a document's
 * size stops being a product of operations and versions.
 */
it('publishes the version enum once however many operations reach it', function (): void {
    $one = (new UirEmitter)->emit(generateDocument(key: 'v2026-06-01')->document);
    $two = (new UirEmitter)->emit(generateDocument(twoOperationVersion(...), 'v2026-06-01')->document);

    foreach (['"enum"', '"x-enum-varnames"', '"x-enumNames"', '"x-enum-descriptions"'] as $member) {
        expect(substr_count($one, $member))->toBe(1)
            ->and(substr_count($two, $member))->toBe(1);
    }

    // And the second operation really is there, reaching the same one declaration.
    expect(substr_count($two, '#/components/parameters/XApiVersion'))->toBe(2);
});

it('leaves a document that declares no version with no version parameter at all', function (): void {
    $document = generateDocument(static function (array $raw): array {
        unset($raw['api_version']);

        return twoOperationVersion($raw);
    }, 'v2026-06-01')->document->toArray();

    expect($document['components'] ?? [])->not->toHaveKey('parameters')
        ->and($document['paths']['/api/versioned-forms']['get'])->not->toHaveKey('parameters')
        ->and($document['paths']['/api/versioned-forms/archived']['get'])->not->toHaveKey('parameters');
});

/*
 * The {@see ComponentNames} invariant, which is why this component may be NAMED where a scoped change's
 * fork may not: the name is a function of the header, and of nothing else that happens to be in the
 * document. Rename the header and it follows; add an unrelated route and it does not move.
 */
it('names the component after the header name', function (): void {
    $document = generateDocument(static function (array $raw): array {
        $raw['api_version']['header'] = 'Accept-Version';

        return $raw;
    }, 'v2026-06-01')->document->toArray();

    expect(array_keys($document['components']['parameters']))->toBe(['AcceptVersion'])
        ->and(parameterRefs($document))->toBe(['#/components/parameters/AcceptVersion'])
        ->and(versionHeaderComponent($document, 'AcceptVersion')['name'])->toBe('Accept-Version');
});

it('does not move the component when an unrelated route joins the document', function (): void {
    $alone = generateDocument(key: 'v2026-06-01')->document->toArray();
    $beside = generateDocument(twoOperationVersion(...), 'v2026-06-01')->document->toArray();

    expect(array_keys($beside['components']['parameters']))->toBe(array_keys($alone['components']['parameters']))
        // Not just the name: the whole declaration, id included, is the same node.
        ->and(versionHeaderComponent($beside))->toBe(versionHeaderComponent($alone))
        // And the second route really did arrive, so this is not a comparison against a no-op.
        ->and($beside['paths'])->toHaveKey('/api/versioned-forms/archived');
});

/*
 * The identity half of the hoist. A component carries ONE id, so every consumer that addresses "this
 * operation's version header" would have lost its node — `ContractIndex`, per-operation coverage,
 * provenance. It does not, because the `$ref` keeps the operation's own parameter id beside it, exactly
 * as a hoisted error response keeps its use site's. `x-docuccino` never survives an OpenAPI emit, so the
 * artifact a consumer reads is a bare `$ref` either way.
 */
it('keeps every operation its own parameter identity beside the $ref', function (): void {
    $document = generateDocument(twoOperationVersion(...), 'v2026-06-01')->document->toArray();

    $id = static fn (string $path): mixed => $document['paths'][$path]['get']['parameters'][0]['x-docuccino']['id'] ?? null;

    expect($id('/api/versioned-forms'))->toStartWith('par:v1:')
        ->and($id('/api/versioned-forms/archived'))->toStartWith('par:v1:')
        // Two operations are two nodes, and neither of them is the component.
        ->and($id('/api/versioned-forms'))->not->toBe($id('/api/versioned-forms/archived'))
        ->and(versionHeaderComponent($document)['x-docuccino']['id'])->toStartWith('par:v1:')
        ->and(versionHeaderComponent($document)['x-docuccino']['id'])->not->toBe($id('/api/versioned-forms'));
});

it('gives two version documents two component identities, and one document one', function (): void {
    $older = generateDocument(key: 'v2026-06-01')->document->toArray();
    $head = generateDocument(key: 'v2026-09-01')->document->toArray();

    expect(versionHeaderComponent($older)['x-docuccino']['id'])
        ->not->toBe(versionHeaderComponent($head)['x-docuccino']['id'])
        // Stable across the enum it publishes, which grows every time the application ships a version:
        // an enum that gained a member is a CHANGE to one node, never one node replaced by another.
        ->and(versionHeaderComponent($older)['x-docuccino']['id'])
        ->toBe(versionHeaderComponent(generateDocument(twoOperationVersion(...), 'v2026-06-01')->document->toArray())['x-docuccino']['id']);
});

/*
 * `ContractIndex` follows a parameter `$ref`, so a request checked against this document is still
 * checked against a real `X-Api-Version` declaration — per operation, with the operation's own id on it.
 * This is the consumer the hoist could most plausibly have broken.
 */
it('indexes the shared header as a parameter of every operation', function (): void {
    $document = generateDocument(twoOperationVersion(...), 'v2026-06-01')->document->toArray();
    $index = ContractIndex::fromArray($document);

    foreach ($index->operations() as $operation) {
        $header = array_values(array_filter(
            $operation->parameters,
            static fn ($parameter): bool => $parameter->name === 'X-Api-Version',
        ));

        expect($header)->toHaveCount(1)
            ->and($header[0]->in)->toBe('header')
            ->and($header[0]->required)->toBeFalse()
            ->and($header[0]->danglingRef)->toBeNull()
            ->and($header[0]->definition['schema']['enum'] ?? null)->toBe(['2026-06-01', '2026-09-01']);
    }

    expect($index->operations())->toHaveCount(2);
});

it('leaves an application that documents the header itself with no component to point at', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms/documented', [VersionedFormController::class, 'documented']);

    $document = generateDocument(static function (array $raw): array {
        $raw['routes'] = ['include' => ['api/versioned-forms/documented']];

        return $raw;
    }, 'v2026-06-01')->document->toArray();

    // Nothing points at it, and a component nothing reaches is bytes a consumer reads past.
    expect($document['components'] ?? [])->not->toHaveKey('parameters')
        ->and($document['paths']['/api/versioned-forms/documented']['get']['parameters'])->toHaveCount(1)
        ->and($document['paths']['/api/versioned-forms/documented']['get']['parameters'][0]['name'])->toBe('X-Api-Version');
});

/*
 * The guard, executed rather than asserted: hand it the document it should refuse — one whose
 * `components.parameters` already holds the name the header asks for — and confirm it refuses to take
 * the name, leaves the incumbent exactly as it found it, and says why. A silent overwrite would delete
 * a parameter somebody else published; a silent rename would change a contract with nothing said.
 */
it('refuses a components.parameters name something else already holds, and says so', function (): void {
    $incumbent = ['name' => 'X-Tenant', 'in' => 'header', 'schema' => ['type' => 'string']];

    $draft = new UirDocumentDraft([
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms API', 'version' => '2026-06-01'],
        'paths' => ['/api/versioned-forms' => ['get' => [
            'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
            'responses' => ['200' => ['description' => 'OK']],
        ]]],
        'components' => ['parameters' => ['XApiVersion' => $incumbent]],
    ]);

    $config = app(DocumentConfigFactory::class)->make('v2026-06-01', versionedFormDocuments()['v2026-06-01'], 'skeleton');
    $context = new DocumentContext($config, 'doc:v2026-06-01');

    (new ApiVersionTransformer(new VersionChangeCollector(base_path())))->transform($draft, $context);

    $document = $draft->toArray();
    $names = array_keys($document['components']['parameters']);
    $published = array_values(array_diff($names, ['XApiVersion']))[0] ?? '';

    expect($document['components']['parameters']['XApiVersion'])->toBe($incumbent)
        ->and($published)->toStartWith('XApiVersion_')
        ->and($document['components']['parameters'][$published]['name'])->toBe('X-Api-Version')
        ->and(parameterRefs($document))->toBe(['#/components/parameters/'.$published])
        ->and(array_map(
            static fn (Diagnostic $diagnostic): string => $diagnostic->code,
            $context->diagnostics->all(),
        ))->toContain('components.name-collision');
});

/*
 * And the collision goes unsaid when nothing was published under the name anyway: the one operation
 * documents the header itself, so there is no component, no moved name, and nothing the reader could
 * act on. A diagnostic that fires here is one that trains people to stop reading the channel.
 */
it('says nothing about a name it never needed', function (): void {
    $draft = new UirDocumentDraft([
        'openapi' => '3.2.0',
        'info' => ['title' => 'Forms API', 'version' => '2026-06-01'],
        'paths' => ['/api/versioned-forms' => ['get' => [
            'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
            'parameters' => [['name' => 'X-Api-Version', 'in' => 'header', 'schema' => ['type' => 'string']]],
            'responses' => ['200' => ['description' => 'OK']],
        ]]],
        'components' => ['parameters' => ['XApiVersion' => ['name' => 'X-Tenant', 'in' => 'header', 'schema' => ['type' => 'string']]]],
    ]);

    $config = app(DocumentConfigFactory::class)->make('v2026-06-01', versionedFormDocuments()['v2026-06-01'], 'skeleton');
    $context = new DocumentContext($config, 'doc:v2026-06-01');

    (new ApiVersionTransformer(new VersionChangeCollector(base_path())))->transform($draft, $context);

    expect(array_keys($draft->toArray()['components']['parameters']))->toBe(['XApiVersion'])
        ->and($draft->toArray()['components']['parameters']['XApiVersion']['name'])->toBe('X-Tenant')
        ->and(array_map(
            static fn (Diagnostic $diagnostic): string => $diagnostic->code,
            $context->diagnostics->all(),
        ))->not->toContain('components.name-collision');
});

/*
 * The half that has to be able to fail: with nothing holding the name, the header takes it plainly and
 * nothing is reported. A guard that fires either way proves nothing about the case above.
 */
it('takes the plain name, silently, when nothing holds it', function (): void {
    $result = generateDocument(key: 'v2026-06-01');

    expect(array_keys($result->document->toArray()['components']['parameters']))->toBe(['XApiVersion'])
        ->and(diagnosticsCoded($result->diagnostics, 'components.name-collision'))->toBe([]);
});

/*
 * The guard suite over the componentized document, which the byte measurement alone never ran: the UIR
 * schema, every emitter down to 3.0, and the differ. A shared parameter is a node each of them models,
 * and a document nothing but a byte count has looked at is not a document anyone can ship.
 */
it('validates, emits and downlevels with the header shared', function (): void {
    $result = generateDocument(twoOperationVersion(...), 'v2026-06-01');

    expect(diagnosticsCoded($result->diagnostics, 'document.schema-invalid'))->toBe([]);

    foreach ([new OpenApi32Emitter, new OpenApi31DownlevelEmitter, new OpenApi30DownlevelEmitter] as $emitter) {
        $emitted = $emitter->emitWithReport($result->document);
        $decoded = json_decode($emitted->output, true);

        expect(array_keys($decoded['components']['parameters']))->toBe(['XApiVersion'])
            ->and($decoded['paths']['/api/versioned-forms']['get']['parameters'])
            ->toBe([['$ref' => '#/components/parameters/XApiVersion']])
            // Nothing about a shared parameter is inexpressible below 3.2, so nothing is dropped.
            ->and(array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $emitted->report->diagnostics))
            ->not->toContain('downlevel.value-not-in-3.1')
            // And x-docuccino is gone, so the use site really is a bare pointer in the artifact.
            ->and($emitted->output)->not->toContain('x-docuccino');
    }
});

it('reads a shared header as one parameter rather than as a change, in both directions', function (): void {
    $document = static fn (string $key): UirDocument => UirDocument::fromArray(
        generateDocument(twoOperationVersion(...), $key)->document->toArray(),
    );

    $differ = new DocumentDiffer;
    $older = $document('v2026-06-01');

    $codes = static fn ($changeset): array => array_map(
        static fn ($change): string => $change->code.' '.$change->path,
        $changeset->changes,
    );

    // A document against itself: the shared parameter pairs with itself through the `$ref`, which is
    // the pairing a differ that could not resolve one would fail at loudly.
    expect($codes($differ->diff($older, $document('v2026-06-01'))))->toBe([])
        // And against the head version, which really does differ — by the renamed field and by the
        // header's `default`, and by nothing about the parameter having moved to components.
        ->and($codes($differ->diff($older, $document('v2026-09-01'))))->not->toBe([]);
});

it('validates through the command over every version', function (): void {
    $this->artisan('docuccino:validate')->assertExitCode(0);
});
