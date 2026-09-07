<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * Changes are applied NEWEST FIRST, each handing the shape of the version below it to the next.
 *
 * Two chained renames prove it: `name` became `label` in 2026-09-01 and `label` became `title` in
 * 2026-12-01, so today's code says `title`. Walking them the other way round leaves the older one with
 * no `label` to rename and the document publishing `label` where it should publish `name`.
 *
 * The two fixtures are named so their FQCN order is the OPPOSITE of the order they apply in — the OLDER
 * change sorts first by name — which is what makes this a test of the ordering rather than of the
 * alphabet. Executed: replacing the collector's comparator with a bare `strcmp($a->class, $b->class)`
 * turns these red.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
});

it('undoes a chain of renames newest first', function (string $version, string $field): void {
    versioningDiagnostics('tests/Fixtures/Versioning/Chained', $version);

    $schema = generateDocument(key: 'v')->document->toArray()['components']['schemas']['FormData'];

    expect(array_keys($schema['properties']))->toBe(['id', $field, 'publishedAt'])
        ->and($schema['required'])->toBe(['id', $field]);
})->with([
    'before both changes' => ['2026-06-01', 'name'],
    'between them' => ['2026-10-01', 'label'],
    'at the newer one' => ['2026-12-01', 'title'],
]);

it('applies a chain without a word of complaint, which is what walking it backwards could not do', function (): void {
    // Backwards, the 2026-09-01 change would look for a `label` nothing had put back yet and raise
    // `versioning.change-target-missing`. Silence here is the assertion.
    expect(versioningDiagnostics('tests/Fixtures/Versioning/Chained', '2026-06-01'))->toBe([]);
});

it('publishes the change prose against the version each one shipped in', function (): void {
    versioningDiagnostics('tests/Fixtures/Versioning/Chained', '2026-06-01');

    $document = generateDocument(key: 'v')->document->toArray();
    $schema = versionHeaderComponent($document)['schema'];

    // One document is configured here, so the enum is its own version alone — the set is read off the
    // documents, and the prose off the changes, which are two different sources on purpose.
    expect($schema['enum'])->toBe(['2026-06-01'])
        ->and($schema)->not->toHaveKey('x-enumDescriptions');
});

/*
 * The other ordering, one level down: the order the VERBS of a single change apply in.
 *
 * It has to be decided somewhere, because an AttributeSet answers per attribute type — so the moment a
 * change carries two kinds of verb, the order the author wrote them in is gone. `VerbOrder` states the
 * rule: a rename goes LAST, because every other verb names its field the way the code spells it today
 * and a rename is the only one that changes what a field is called.
 *
 * Executed, not asserted. Swap the two halves of `VerbOrder::read()` and the pair below goes red
 * together: the document publishes `required: ['id', 'name']` instead of `['id']`, and the build
 * reports `versioning.change-target-missing` against a declaration that is written perfectly correctly.
 */
it('takes the guarantee off the field before the rename re-spells it', function (): void {
    versioningDiagnostics('tests/Fixtures/Versioning/VerbOrder');

    $schema = generateDocument(key: 'v')->document->toArray()['components']['schemas']['FormData'];

    // `title` lost its guarantee while it was still called `title`, and the rename then took what was
    // left back to the older spelling. Rename-first, the required verb would have looked for a `title`
    // that had already become `name`, edited nothing, and left `name` guaranteed.
    expect(array_keys($schema['properties']))->toBe(['id', 'name', 'publishedAt'])
        ->and($schema['required'])->toBe(['id']);
});

it('applies both verbs of one change without complaint, which the other order could not', function (): void {
    expect(versioningDiagnostics('tests/Fixtures/Versioning/VerbOrder'))->toBe([]);
});

/*
 * The same rule, confirmed rather than assumed for the two renames that reach a REQUEST body and a
 * parameter — the question the order has to be re-asked for every time a rename verb is added.
 *
 * The request one is the identical problem on the other half of the wire: `MadeRequestFieldOptional`
 * names `subtitle` the way the code spells it TODAY, so a rename running first leaves it looking for a
 * field that has already become `caption`. Executed the same way its response sibling is — move the
 * `#[RenamedRequestField]` loop in `VerbOrder::read()` above the required-ness ones and this goes red,
 * with `required` short of `caption` and a `versioning.change-target-missing` against a declaration
 * that is written perfectly correctly.
 */
it('puts a request field back into required before the rename re-spells it', function (): void {
    $request = versionedArticleSchemas('tests/Fixtures/Versioning/RequestVerbOrder')['request'];

    expect(array_keys($request['properties']))
        ->toBe(['id', 'heading', 'body', 'secret', 'internal', 'caption', 'author', 'metadata', 'overrides'])
        // `subtitle` was demanded while it was still called `subtitle`, and the rename then took what
        // was left back to the older spelling — in `properties` and in `required` together.
        ->and($request['required'])->toBe(['id', 'heading', 'body', 'secret', 'internal', 'caption', 'metadata', 'overrides']);
});

it('applies both request verbs of one change without complaint, which the other order could not', function (): void {
    expect(versioningDiagnostics('tests/Fixtures/Versioning/RequestVerbOrder', route: 'api/articles'))->toBe([]);
});

/*
 * And the parameter verb, whose position in the order is NOT observable today and is fixed anyway.
 * Nothing else in the vocabulary can name a parameter, so there is no target it could rot and none that
 * could rot its own — which is a claim worth executing rather than leaving as a sentence, because it is
 * the reason the rule was not re-derived for it.
 *
 * One order runs, and there is no second one to run: `VerbOrder::read()` is the only thing that decides
 * it, an `AttributeSet` has already lost the order the author wrote by the time anything can ask, and
 * this verb reaches `operation.parameters[]` while its neighbour reaches `components.schemas` — disjoint
 * positions, so there is nothing an order could change. What this row proves is that both verbs of one
 * change arrive; the executed guard for the ORDER is the two pairs above, which go red when the halves
 * of `read()` are swapped.
 */
it('applies a parameter rename and a schema rename declared on one change, each to its own half', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-search', [VersionedFormController::class, 'search']);
    bindVersionedRequestEngine();

    $diagnostics = versioningDiagnostics('tests/Fixtures/Versioning/ParameterBesideSchema', route: 'api/versioned-*');
    $document = generateDocument(key: 'v')->document->toArray();

    expect(array_map(static fn (Diagnostic $d): string => $d->code, $diagnostics))->toBe([])
        ->and(array_column($document['paths']['/api/versioned-search']['get']['parameters'], 'name'))->toBe(['q', 'trace_id'])
        ->and(array_keys($document['components']['schemas']['FormData']['properties']))->toBe(['id', 'name', 'publishedAt']);
});
