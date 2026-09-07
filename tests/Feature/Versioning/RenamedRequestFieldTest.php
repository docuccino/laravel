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
 * `#[RenamedRequestField]`, against real builds of the workbench.
 *
 * The verb this suite exists for is the one the response rename could not reach. `#[RenamedResponseField]`
 * shipped and cannot address a request body at all, which left half of every rename in a version history
 * undeclarable — so the assertions below are mostly about the NODE, not about the edit: `properties` and
 * `required` move exactly the way the response side's already do, and what is new is which of a class's
 * two published shapes gets moved.
 *
 * `ArticleData` is the honest fixture for that, and the reason is spelled out where it bites: `POST
 * /api/articles` publishes it as `ArticleRequest` on the way in and `Article` on the way out, the two
 * carry different identities, and the same field is spelled `heading` in the request and `headline` in
 * the response. A verb that resolved the wrong node would therefore find NOTHING to rename and report a
 * perfectly correct declaration as rotted — which is a visible failure rather than a silent one, and is
 * what makes these assertions worth anything.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
});

it('renames a request field, and leaves the response shape of the same class alone', function (): void {
    $schemas = versionedArticleSchemas('tests/Fixtures/Versioning/RenamedRequest');

    expect(array_keys($schemas['request']['properties']))
        ->toBe(['id', 'name', 'body', 'secret', 'internal', 'subtitle', 'author', 'metadata', 'overrides'])
        // The response half of the SAME class still spells it the way the code spells it there.
        ->and(array_keys($schemas['response']['properties']))
        ->toBe(['id', 'headline', 'body', 'subtitle', 'author', 'metadata', 'overrides']);
});

/*
 * Load-bearing, and the same sentence the response side's guard makes for the same reason: a `required`
 * still naming today's field would mark a body carrying the OLD name invalid and one carrying the new
 * name valid, which is the exact disagreement a per-version contract test exists to catch. It matters
 * MORE on the request side, because `required` on the way in is what refuses a client's body outright.
 */
it('rewrites the request required list with the properties it names', function (): void {
    $request = versionedArticleSchemas('tests/Fixtures/Versioning/RenamedRequest')['request'];

    expect($request['required'])->toBe(['id', 'name', 'body', 'secret', 'internal', 'metadata', 'overrides'])
        ->and($request['required'])->not->toContain('heading');
});

it('renames a response field, and leaves the request shape of the same class alone', function (): void {
    $schemas = versionedArticleSchemas('tests/Fixtures/Versioning/RenamedResponseOnly');

    expect(array_keys($schemas['response']['properties']))
        ->toBe(['id', 'name', 'body', 'subtitle', 'author', 'metadata', 'overrides'])
        ->and(array_keys($schemas['request']['properties']))
        ->toBe(['id', 'heading', 'body', 'secret', 'internal', 'subtitle', 'author', 'metadata', 'overrides']);
});

/*
 * The identity itself, read out of a real emitted document and stated independently of the code that
 * mints it. A guard that asked the transformer which node it chose would agree with whatever the
 * transformer did; this asks the DOCUMENT what it published each shape under, and then asks the verb.
 */
it('resolves a request rename to the identity the build published the request body under', function (): void {
    versioningDiagnostics(null, route: 'api/articles');

    $schemas = generateDocument(key: 'v')->document->toArray()['components']['schemas'];
    $identity = new IdentityGenerator;

    $request = SchemaFacet::Request->identityOf(ArticleData::class, $identity);
    $response = SchemaFacet::Response->identityOf(ArticleData::class, $identity);

    expect($schemas['ArticleRequest']['x-docuccino']['id'])->toBe($request)
        ->and($schemas['Article']['x-docuccino']['id'])->toBe($response)
        ->and($request)->not->toBe($response)
        // And the class pinned its own diff identity with #[SchemaId], so neither node is minted from
        // the class name — a verb resolving the FQCN would find no schema at all.
        ->and($response)->not->toBe($identity->namedSchemaId(ArticleData::class));
});

it('renames two request fields on one change, in properties and in required alike', function (): void {
    $request = versionedArticleSchemas('tests/Fixtures/Versioning/RenamedRequestPair')['request'];

    expect(array_keys($request['properties']))
        ->toBe(['id', 'name', 'content', 'secret', 'internal', 'subtitle', 'author', 'metadata', 'overrides'])
        ->and($request['required'])->toBe(['id', 'name', 'content', 'secret', 'internal', 'metadata', 'overrides']);
});

it('says nothing when a request rename applies', function (): void {
    expect(versioningDiagnostics('tests/Fixtures/Versioning/RenamedRequest', route: 'api/articles'))->toBe([]);
});

/*
 * The report a reader would otherwise be able to prove wrong: the document publishes an `Article` with
 * a `headline` right there, and the change was skipped. Saying which of the two shapes was looked in is
 * the whole difference between a diagnostic and a puzzle.
 */
it('says a request rename has rotted, naming the request body it looked in', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RenamedRequestMissing', route: 'api/articles');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.change-target-missing')
        ->and($diagnostics[0]->message)->toContain('renames "headline"')
        ->toContain('which the request body schema for')
        ->and($diagnostics[0]->help)->toContain('as it is spelled today');
});

it('says a request rename found no request body, in a document that publishes the response shape', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RenamedRequestUnresolved');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.schema-unresolved')
        ->and($diagnostics[0]->message)->toContain('publishes no request body schema for')
        ->toContain(FormData::class);

    // And the response shape it names is left exactly as the code publishes it.
    $schema = generateDocument(key: 'v')->document->toArray()['components']['schemas']['FormData'];

    expect(array_keys($schema['properties']))->toBe(['id', 'title', 'publishedAt']);
});

it('refuses to rename a request field onto one the body already accepts', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RenamedRequestTaken', route: 'api/articles');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.change-invalid')
        ->and($diagnostics[0]->message)->toContain('the request body schema for')
        ->toContain('already publishes a field called "body"')
        ->toContain('collapse two fields into one');

    // And nothing moved: the request body is the shape the code publishes.
    expect(array_keys(versionedArticleSchemas('tests/Fixtures/Versioning/RenamedRequestTaken')['request']['properties']))
        ->toBe(['id', 'heading', 'body', 'secret', 'internal', 'subtitle', 'author', 'metadata', 'overrides']);
});

it('refuses a request rename with an empty end, naming the declaration it read', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/EmptyRenamedRequest', route: 'api/articles');

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe(['versioning.change-invalid'])
        // The declaration is named, because the two rename verbs share one refusal and a reader told
        // only "one of its rename declarations" would not know which attribute to go and look at.
        ->and($diagnostics[0]->message)->toContain('#[RenamedRequestField] declarations leaves `from:` or `to:` empty');
});

it('leaves a request rename that shipped at or before this version alone', function (): void {
    expect(versioningDiagnostics('tests/Fixtures/Versioning/RenamedRequest', '2026-09-01', 'api/articles'))->toBe([]);

    $request = generateDocument(key: 'v')->document->toArray()['components']['schemas']['ArticleRequest'];

    expect(array_keys($request['properties']))
        ->toBe(['id', 'heading', 'body', 'secret', 'internal', 'subtitle', 'author', 'metadata', 'overrides']);
});
