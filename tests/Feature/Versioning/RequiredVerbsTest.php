<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ArticleData;
use Docuccino\Laravel\Versioning\SchemaFacet;
use Illuminate\Routing\Router;
use Workbench\App\Data\FormData;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * The three required-ness verbs, against real builds of the workbench.
 *
 * `required` is where a version document's promises actually live: `properties` says what a field is
 * called and what shape it has, and `required` is the only place the document says whether the field
 * will be there at all. All three verbs move that one keyword and touch nothing else.
 *
 * Two of the four schemas below are the same class. `ArticleData` is the request body of
 * `POST /api/articles` and the response of the same operation, and the document identifies the two
 * separately — so these are also the guard on the identity a request verb resolves.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
});

/**
 * One document's `FormData`, built with the changes in `$dir` applied.
 *
 * @return array<string, mixed>
 */
function versionedFormSchema(string $dir): array
{
    versioningDiagnostics($dir);

    /** @var array<string, mixed> $schema */
    $schema = generateDocument(key: 'v')->document->toArray()['components']['schemas']['FormData'];

    return $schema;
}

/**
 * The two shapes `POST /api/articles` publishes for one class: the body a client sends, and the
 * article it gets back.
 *
 * @return array{request: array<string, mixed>, response: array<string, mixed>}
 */
function versionedArticleSchemas(string $dir): array
{
    versioningDiagnostics($dir, route: 'api/articles');

    /** @var array<string, array<string, mixed>> $schemas */
    $schemas = generateDocument(key: 'v')->document->toArray()['components']['schemas'];

    return ['request' => $schemas['ArticleRequest'], 'response' => $schemas['Article']];
}

it('takes a field out of required for the versions before it was guaranteed', function (): void {
    // `properties` is untouched: the field was published then and is published now, and only the
    // promise about it moved.
    $schema = versionedFormSchema('tests/Fixtures/Versioning/RequiredResponse');

    expect(array_keys($schema['properties']))->toBe(['id', 'title', 'publishedAt']);
});

it('drops the required keyword rather than publishing an empty one', function (): void {
    // Both of the schema's required fields go, and `required: []` is a keyword that says nothing —
    // the canonicalizer prefers absence, so writing the empty list back would be a member no emitter
    // publishes.
    $schema = versionedFormSchema('tests/Fixtures/Versioning/RequiredResponse');

    expect($schema)->not->toHaveKey('required');
});

it('puts a response field back into required for the versions that always sent it', function (): void {
    $schema = versionedFormSchema('tests/Fixtures/Versioning/OptionalResponse');

    expect($schema['required'])->toBe(['id', 'title', 'publishedAt'])
        ->and(array_keys($schema['properties']))->toBe(['id', 'title', 'publishedAt']);
});

/*
 * The request half, and the reason it needs a test of its own: `ArticleData` is published TWICE, and
 * the two publications have different identities. The response guarantees `author` and the request
 * body does not, so a verb that resolved the response node would find the field already required,
 * decline, and say so — silence plus an edited request node is the proof it resolved the right one.
 */
it('puts a request field back into required, and leaves the response shape of the same class alone', function (): void {
    $before = versionedArticleSchemas('tests/Fixtures/Versioning/RequiredMissing')['response'];
    $schemas = versionedArticleSchemas('tests/Fixtures/Versioning/OptionalRequestBody');

    expect($schemas['request']['required'])->toContain('author')
        ->and($schemas['response']['required'])->toBe($before['required'])
        ->and($schemas['response']['required'])->toBe(['id', 'headline', 'body', 'author', 'metadata', 'overrides']);
});

it('edits the response shape and leaves the request one alone, over that same class', function (): void {
    $schemas = versionedArticleSchemas('tests/Fixtures/Versioning/OptionalResponseBody');

    expect($schemas['response']['required'])->toContain('subtitle')
        ->and($schemas['request']['required'])->not->toContain('subtitle');
});

/*
 * The identity itself, stated independently of the code that mints it: the id the document publishes
 * on each of the two nodes, read out of a real build, against what a verb resolves. A guard that asked
 * the transformer for its own answer would agree with whatever the transformer did.
 */
it('resolves each facet to the identity the build actually published it under', function (): void {
    versioningDiagnostics(null, route: 'api/articles');

    $schemas = generateDocument(key: 'v')->document->toArray()['components']['schemas'];
    $identity = new IdentityGenerator;

    expect($schemas['ArticleRequest']['x-docuccino']['id'])
        ->toBe(SchemaFacet::Request->identityOf(ArticleData::class, $identity))
        ->and($schemas['Article']['x-docuccino']['id'])
        ->toBe(SchemaFacet::Response->identityOf(ArticleData::class, $identity))
        ->and(SchemaFacet::Request->identityOf(ArticleData::class, $identity))
        ->not->toBe(SchemaFacet::Response->identityOf(ArticleData::class, $identity));
});

/*
 * And the half that would otherwise be silent: `ArticleData` pins its diff identity with `#[SchemaId]`,
 * so neither node is minted from the class name. A verb resolving the FQCN would find no schema and
 * report that the document publishes none — of a class the document plainly publishes twice.
 */
it('resolves a class that pinned its own diff identity', function (): void {
    $identity = new IdentityGenerator;

    expect(SchemaFacet::Response->identityOf(ArticleData::class, $identity))
        ->not->toBe($identity->namedSchemaId(ArticleData::class))
        ->and(versionedArticleSchemas('tests/Fixtures/Versioning/OptionalResponseBody')['response']['required'])
        ->toContain('subtitle');
});

/*
 * Order is a function of the SCHEMA, not of the order the verbs ran: a name goes in at the index equal
 * to the number of entries already standing that `properties` puts before it. `author` is the sixth
 * property and two of the ones before it are optional, so appending and positioning give visibly
 * different answers.
 */
it('inserts into required where the properties list puts the field, not at the end', function (): void {
    $request = versionedArticleSchemas('tests/Fixtures/Versioning/OptionalRequestBody')['request'];

    expect(array_keys($request['properties']))->toBe(['id', 'heading', 'body', 'secret', 'internal', 'subtitle', 'author', 'metadata', 'overrides'])
        ->and($request['required'])->toBe(['id', 'heading', 'body', 'secret', 'internal', 'author', 'metadata', 'overrides']);
});

/*
 * And the property that makes positioning worth the arithmetic, executed: two verbs adding two fields
 * land the same way round whichever ran first. The two fixtures write the same pair in opposite
 * orders — which is an order the build CAN see, because an AttributeSet keeps the written order within
 * one attribute type — and appending would put them out in the order they were written.
 */
it('lands two additions the same way round whichever was written first', function (): void {
    $written = versionedArticleSchemas('tests/Fixtures/Versioning/OptionalRequestPair')['request'];
    $reversed = versionedArticleSchemas('tests/Fixtures/Versioning/OptionalRequestPairReversed')['request'];

    expect($written['required'])
        ->toBe(['id', 'heading', 'body', 'secret', 'internal', 'subtitle', 'author', 'metadata', 'overrides'])
        ->and($reversed['required'])->toBe($written['required']);
});

/*
 * A required change moves no key, so nothing an example carries has to be rewritten — and this is the
 * assertion that it really is nothing, rather than the rewriter being invoked and quietly deciding so.
 * The example rewriter is reached from the rename's path, and a verb inheriting it wrongly would drop
 * examples for a change that moved nothing.
 */
it('leaves every example exactly where it stood', function (): void {
    versioningDiagnostics('tests/Fixtures/Versioning/OptionalResponse');
    $moved = generateDocument(key: 'v')->document->toArray();

    versioningDiagnostics(null);
    $untouched = generateDocument(key: 'v')->document->toArray();

    $media = versionedFormMedia($moved);

    expect($media['example'])->toBe(versionedFormMedia($untouched)['example'])
        ->and($media['example'])->toBe([['id' => 1, 'title' => 'Onboarding', 'publishedAt' => '2026-08-01T09:00:00Z']]);
});

it('says nothing when a verb applies', function (): void {
    expect(versioningDiagnostics('tests/Fixtures/Versioning/OptionalResponse'))->toBe([]);
});

/*
 * What the weaker guarantee comes to. A rename has a distinguishable before and after, so it can tell
 * `renamed` from `taken` from `absent`; "remove from required" applied twice is a no-op, so nothing
 * here can report that the edit already ran. What it CAN report is the code disagreeing with the
 * declaration — the change says the field's required-ness moved and the schema already says what the
 * older version would — and that is what this says, in those words.
 */
it('says a required change describes something the code does not', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RequiredUnchanged');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.change-target-unchanged')
        ->and($diagnostics[0]->message)->toContain('the response field "publishedAt"')
        ->toContain('became required')
        ->toContain('already publishes it as optional')
        ->and($diagnostics[0]->help)->toContain('#[MadeResponseFieldRequired]');

    // And the document is left at the shape the code publishes.
    expect(versionedFormSchema('tests/Fixtures/Versioning/RequiredUnchanged')['required'])->toBe(['id', 'title']);
});

it('says a required change has rotted when the field it names is gone from the code', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RequiredMissing');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.change-target-missing')
        ->and($diagnostics[0]->message)->toContain('names "headline"')
        ->and($diagnostics[0]->help)->toContain('as it is spelled today');
});

/*
 * The report a reader would otherwise be able to prove wrong: the document publishes a `FormData`
 * right there, and the change was skipped. Saying which of the two shapes was missing is the whole
 * difference between a diagnostic and a puzzle.
 */
it('says a request verb found no request body, in a document that publishes the response shape', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RequestUnresolved');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.schema-unresolved')
        ->and($diagnostics[0]->message)->toContain('publishes no request body schema for')
        ->and($diagnostics[0]->message)->toContain(FormData::class);

    // And the response shape it names is left exactly as the code publishes it.
    expect(versionedFormSchema('tests/Fixtures/Versioning/RequestUnresolved')['required'])->toBe(['id', 'title']);
});

it('refuses a verb that names no field', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/EmptyRequired');

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe(['versioning.change-invalid'])
        ->and($diagnostics[0]->message)->toContain('leaves `schema:` or `field:` empty')
        ->and($diagnostics[0]->help)->toContain('as the code spells it today');
});

it('leaves a required change that shipped at or before this version alone', function (): void {
    expect(versioningDiagnostics('tests/Fixtures/Versioning/RequiredUnchanged', '2026-09-01'))->toBe([]);

    $schema = generateDocument(key: 'v')->document->toArray()['components']['schemas']['FormData'];

    expect($schema['required'])->toBe(['id', 'title']);
});
