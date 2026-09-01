<?php

declare(strict_types=1);

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
