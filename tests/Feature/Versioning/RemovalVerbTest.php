<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * `#[RemovedResponseField]`, against real builds of the workbench.
 *
 * It is the one verb whose fact is gone from the code, so it is the one that declares a shape — and the
 * three readings of that declaration are what most of this file is about. A class the document already
 * publishes becomes a `$ref` at that component, one of OpenAPI's own type names becomes that `type`,
 * and anything else publishes an unconstrained field with a diagnostic beside it.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
    $router->get('api/versioned-forms/archived', [VersionedFormController::class, 'archived']);
});

/**
 * `FormData` as the document publishes it with the changes in `$dir` applied.
 *
 * @return array<string, mixed>
 */
function removedFormSchema(string $dir, string $route = 'api/versioned-forms'): array
{
    versioningDiagnostics($dir, route: $route);

    /** @var array<string, mixed> $schema */
    $schema = generateDocument(key: 'v')->document->toArray()['components']['schemas']['FormData'];

    return $schema;
}

it('puts a removed field back with the shape the declaration states', function (): void {
    $schema = removedFormSchema('tests/Fixtures/Versioning/Removed');

    expect($schema['properties']['subtotal'])->toBe([
        'type' => 'integer',
        'description' => 'The form total before tax, in cents.',
    ]);
});

/*
 * Where it lands, and why it is not the end of the list. The position is counted against the names
 * already standing, so `subtotal` goes after `id` and `title` — which are the two of the three that
 * sort before it — rather than wherever this verb happened to run.
 */
it('puts it where the names say, not where the verb ran', function (): void {
    $schema = removedFormSchema('tests/Fixtures/Versioning/Removed');

    expect(array_keys($schema['properties']))->toBe(['id', 'title', 'subtotal', 'publishedAt']);
});

it('lands two re-added fields the same way round whichever was written first', function (): void {
    // The property that makes the counting worth doing, executed: the two fixtures write one pair of
    // removals in opposite orders, and an AttributeSet really does keep that order within one type.
    $written = removedFormSchema('tests/Fixtures/Versioning/RemovedPair');
    $reversed = removedFormSchema('tests/Fixtures/Versioning/RemovedPairReversed');

    expect(array_keys($written['properties']))->toBe(['archivedAt', 'id', 'title', 'subtotal', 'publishedAt'])
        ->and(array_keys($reversed['properties']))->toBe(array_keys($written['properties']));
});

it('leaves required alone for a field the older versions did not promise', function (): void {
    $schema = removedFormSchema('tests/Fixtures/Versioning/Removed');

    expect($schema['required'])->toBe(['id', 'title']);
});

it('names a field the older versions always sent in required, where the properties list puts it', function (): void {
    $schema = removedFormSchema('tests/Fixtures/Versioning/RemovedRequired');

    expect($schema['required'])->toBe(['id', 'title', 'subtotal']);
});

/*
 * Reading 2, through a build rather than through the table on its own: the six OpenAPI type names, and
 * the two suffixes that are the whole of the grammar beside them.
 */
it('reads the OpenAPI type names and their suffixes', function (): void {
    $properties = removedFormSchema('tests/Fixtures/Versioning/RemovedTypeSpellings')['properties'];

    expect($properties['tags'])->toBe(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($properties['retiredAt'])->toBe(['type' => ['string', 'null']])
        ->and($properties['scores'])->toBe(['type' => ['array', 'null'], 'items' => ['type' => 'number']]);
});

it('says nothing about a removal that stated no type at all', function (): void {
    // An author who cannot say what the field held asks for the unconstrained shape on purpose, and
    // gets it without being told off for it — a warning nobody can act on is what trains a reader to
    // stop reading the channel.
    expect(versioningDiagnostics('tests/Fixtures/Versioning/RemovedUntyped'))->toBe([])
        ->and(removedFormSchema('tests/Fixtures/Versioning/RemovedUntyped')['properties']['annotations'])->toBe([]);
});

/*
 * Reading 3, executed: a type that is neither a published class nor an OpenAPI type name. The field is
 * still published — a valid vague schema beats a precise false one — and the build says the declaration
 * asked for something it did not get.
 */
it('publishes an unconstrained field for a type it could not read, and says so', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RemovedUnreadableType');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.type-unresolved')
        ->and($diagnostics[0]->message)->toContain('App\\Support\\Money')
        ->toContain('published with no shape at all')
        ->and($diagnostics[0]->help)->toContain('`string`')
        ->toContain('Leave `type:` out');

    expect(removedFormSchema('tests/Fixtures/Versioning/RemovedUnreadableType')['properties']['price'])->toBe([]);
});

it('says nothing when a removal applies', function (): void {
    expect(versioningDiagnostics('tests/Fixtures/Versioning/Removed'))->toBe([]);
});

it('says a removal describes something the code does not', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RemovedStillThere');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.change-target-unchanged')
        ->and($diagnostics[0]->message)->toContain('the response field "title"')
        ->toContain('was removed, and the schema still publishes it')
        ->and($diagnostics[0]->help)->toContain('#[RemovedResponseField]');

    // And nothing is written twice: `title` keeps the one shape the code publishes.
    expect(removedFormSchema('tests/Fixtures/Versioning/RemovedStillThere')['properties']['title'])
        ->toBe(['type' => 'string']);
});

it('says a removal names a schema this document publishes nothing for', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RemovedUnresolved');

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.schema-unresolved')
        ->and($diagnostics[0]->message)->toContain('App\\Data\\LedgerData');
});

it('refuses a removal that names no field', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RemovedEmpty');

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe(['versioning.change-invalid'])
        ->and($diagnostics[0]->message)->toContain('leaves `schema:` or `field:` empty')
        ->and($diagnostics[0]->help)->toContain('the versions before it published it');
});

/*
 * The examples. A field put back OPTIONAL leaves every example valid — an absent optional member is
 * fine — and one put back REQUIRED does not, because no example can carry a field the code does not
 * have. The second is the case where publishing nothing beats publishing something the schema rejects.
 */
it('leaves every example where it stood for a field nobody promised', function (): void {
    versioningDiagnostics('tests/Fixtures/Versioning/Removed');
    $derived = generateDocument(key: 'v')->document->toArray();

    expect(versionedFormMedia($derived)['example'])
        ->toBe([['id' => 1, 'title' => 'Onboarding', 'publishedAt' => '2026-08-01T09:00:00Z']]);
});

it('drops an example that cannot carry a field the version demands, and says where', function (): void {
    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/RemovedRequired');
    $derived = generateDocument(key: 'v')->document->toArray();

    expect(versionedFormMedia($derived))->not->toHaveKey('example')
        ->and(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe(['versioning.example-dropped'])
        ->and($diagnostics[0]->message)->toContain('puts the required field "subtotal" back')
        ->toContain('/paths/~1api~1versioned-forms/get/responses/200/content/application~1json/example')
        ->and($diagnostics[0]->help)->toContain('worse than none');
});

/*
 * The verb order. A removal counts where its field lands against the names already standing, and a
 * rename changes one of those names — so the two are not commutative and something has to decide.
 * `VerbOrder` puts the rename last. Swap the two halves of `VerbOrder::read()` and this goes red:
 * `subtotal` lands before `name` instead of after it.
 */
it('counts a re-added field against the names the code publishes, not the ones the rename invents', function (): void {
    $schema = removedFormSchema('tests/Fixtures/Versioning/RemovedThenRenamed');

    expect(array_keys($schema['properties']))->toBe(['id', 'name', 'subtotal', 'publishedAt'])
        ->and(versioningDiagnostics('tests/Fixtures/Versioning/RemovedThenRenamed'))->toBe([]);
});

it('leaves a removal that shipped at or before this version alone', function (): void {
    expect(versioningDiagnostics('tests/Fixtures/Versioning/Removed', '2026-09-01'))->toBe([]);

    $schema = generateDocument(key: 'v')->document->toArray()['components']['schemas']['FormData'];

    expect(array_keys($schema['properties']))->toBe(['id', 'title', 'publishedAt']);
});

/*
 * Reading 1: the removed field held a class the document already publishes, so the older version's
 * document points at that component. No converter, no second type grammar, and a name a generated
 * client can keep.
 */
function removedArticleSchemas(string $dir, string $version = '2026-06-01'): array
{
    versioningDiagnostics($dir, $version, route: 'api/articles');

    /** @var array<string, array<string, mixed>> $schemas */
    $schemas = generateDocument(key: 'v')->document->toArray()['components']['schemas'];

    return $schemas;
}

it('points a re-added field at the component the document publishes for its class', function (): void {
    $schemas = removedArticleSchemas('tests/Fixtures/Versioning/RemovedRef');

    expect($schemas['Article']['properties']['reviewer'])->toBe([
        '$ref' => '#/components/schemas/AuthorData',
        'description' => 'Who signed the article off.',
    ])
        // Resolved by identity: `ArticleData` pins its own with `#[SchemaId]`, so a verb minting from the
        // class name would have found no schema at all and reported that the document publishes none.
        ->and(versioningDiagnostics('tests/Fixtures/Versioning/RemovedRef', route: 'api/articles'))->toBe([]);
});

/*
 * And the argument that makes the `$ref` reading worth having, executed rather than taken on faith:
 * deriving a version rewrites the WHOLE document, so the component the pointer names is itself
 * downgraded by every other change. The field re-added here holds the author shape of the version
 * asking, not today's.
 */
it('points at the version\'s own shape of that component, not at today\'s', function (): void {
    // Two changes: the removal shipped in 2026-12-01, and an author's `full_name` became `name` in
    // 2026-09-01. Between them, only the removal is undone.
    $between = removedArticleSchemas('tests/Fixtures/Versioning/RemovedRefComposed', '2026-10-01');

    expect($between['Article']['properties']['reviewer'])->toBe(['$ref' => '#/components/schemas/AuthorData'])
        ->and(array_keys($between['AuthorData']['properties']))->toBe(['name', 'email']);

    // Below both, the SAME pointer resolves to the older author shape — because derivation rewrote the
    // component too. That is the whole of why this reading needs no converter and no new component.
    $below = removedArticleSchemas('tests/Fixtures/Versioning/RemovedRefComposed', '2026-06-01');

    expect($below['Article']['properties']['reviewer'])->toBe(['$ref' => '#/components/schemas/AuthorData'])
        ->and(array_keys($below['AuthorData']['properties']))->toBe(['full_name', 'email']);
});

/*
 * Under a partial scope. The operations in scope get a private copy of the schema; the rest go on
 * sharing the component, which is what the fork rule says and what the copy is for.
 */
function removedScopeDocument(string $dir): array
{
    config()->set('docuccino.documents', ['v' => [
        'info' => ['title' => 'Forms API', 'version' => '2026-06-01'],
        'routes' => ['include' => ['api/versioned-forms*']],
        'error_responses' => 'none',
        'api_version' => ['changes' => [$dir]],
    ]]);

    return generateDocument(key: 'v')->document->toArray();
}

it('gives only the operations in scope the field back', function (): void {
    $document = removedScopeDocument('tests/Fixtures/Versioning/RemovedScoped');

    $inScope = $document['paths']['/api/versioned-forms']['get']['responses']['200']['content']['application/json']['schema']['items'];
    $outOfScope = $document['paths']['/api/versioned-forms/archived']['get']['responses']['200']['content']['application/json']['schema']['items'];

    expect(array_keys($inScope['properties']))->toBe(['id', 'title', 'subtotal', 'publishedAt'])
        ->and($outOfScope)->toBe(['$ref' => '#/components/schemas/FormData'])
        ->and(array_keys($document['components']['schemas']['FormData']['properties']))->toBe(['id', 'title', 'publishedAt']);
});

/*
 * And what a scoped removal cannot do. A copy of the schema whose re-added field points BACK at the
 * schema being copied would publish the older shape at the top and today's one level down — the
 * self-reference limit, reached through a verb rather than through the way a class was written. The
 * operation is left at the shape the code publishes and the build says why.
 */
it('refuses to fork an operation whose re-added field points back at the schema being copied', function (): void {
    $result = (function (): array {
        $document = removedScopeDocument('tests/Fixtures/Versioning/RemovedScopedSelfReferential');

        return [$document, generateDocument(key: 'v')->diagnostics];
    })();

    [$document, $diagnostics] = $result;

    $versioning = array_values(array_filter(
        $diagnostics,
        static fn (Diagnostic $d): bool => str_starts_with($d->code, 'versioning.'),
    ));

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $versioning))->toBe(['versioning.scope-unforkable'])
        ->and($versioning[0]->message)->toContain('would point back at the shared component')
        ->toContain('GET /api/versioned-forms')
        ->and($versioning[0]->help)->toContain('Drop the #[AppliesTo]');

    // Nothing half-written: both operations still share the component, and it still says what the code
    // says.
    expect($document['paths']['/api/versioned-forms']['get']['responses']['200']['content']['application/json']['schema']['items'])
        ->toBe(['$ref' => '#/components/schemas/FormData'])
        ->and(array_keys($document['components']['schemas']['FormData']['properties']))->toBe(['id', 'title', 'publishedAt']);
});
