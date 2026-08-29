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
