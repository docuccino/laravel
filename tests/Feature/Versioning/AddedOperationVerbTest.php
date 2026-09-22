<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * `#[AddedOperation]`, against real builds of the workbench.
 *
 * The one verb that removes a node rather than editing one, so what it owes proof of is what goes with
 * the operation and what does not: the path item it emptied goes, the components it was the last reader
 * of stay, and every other operation is exactly where it was.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindVersionedRequestEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
    $router->get('api/versioned-forms/archived', [VersionedFormController::class, 'archived']);
    $router->get('api/versioned-search', [VersionedFormController::class, 'search']);
});

/**
 * The paths the document publishes with the changes in `$dir` applied, each as `METHOD /path`.
 *
 * @return list<string>
 */
function versionedOperations(?string $dir): array
{
    versioningDiagnostics($dir, route: 'api/versioned-*');

    /** @var array<string, array<string, mixed>> $paths */
    $paths = generateDocument(key: 'v')->document->toArray()['paths'];

    $operations = [];
    foreach ($paths as $path => $item) {
        foreach (array_keys($item) as $method) {
            if (in_array($method, ['get', 'put', 'post', 'delete', 'patch', 'head', 'options', 'trace'], true)) {
                $operations[] = strtoupper((string) $method).' '.$path;
            }
        }
    }

    return $operations;
}

/**
 * @return list<string>
 */
function versionedOperationCodes(?string $dir): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code,
        versioningDiagnostics($dir, route: 'api/versioned-*'),
    );
}

it('publishes every operation where no change says otherwise', function (): void {
    // The denominator. Without it the assertions below would pass just as well against a document that
    // had stopped publishing anything at all.
    expect(versionedOperations(null))->toBe([
        'GET /api/versioned-forms',
        'GET /api/versioned-forms/archived',
        'GET /api/versioned-search',
    ]);
});

it('leaves out an operation the version added', function (): void {
    expect(versionedOperations('tests/Fixtures/Versioning/OperationAdded'))->toBe([
        'GET /api/versioned-forms',
        'GET /api/versioned-search',
    ]);
});

it('reads a selector as an operationId as well as a signature', function (): void {
    expect(versionedOperations('tests/Fixtures/Versioning/OperationAddedById'))
        ->toBe(versionedOperations('tests/Fixtures/Versioning/OperationAdded'));
});

it('reads a wildcard the way a route filter does', function (): void {
    expect(versionedOperations('tests/Fixtures/Versioning/OperationAddedWildcard'))->toBe([
        'GET /api/versioned-forms',
        'GET /api/versioned-search',
    ]);
});

/*
 * The path goes with its last operation. An empty path item is not a path that publishes nothing — it is
 * one a client can see and get nothing from — and absence is OpenAPI's own shape for "this version did
 * not serve it".
 */
it('takes the path item away with the last operation standing in it', function (): void {
    versioningDiagnostics('tests/Fixtures/Versioning/OperationAdded', route: 'api/versioned-*');

    expect(generateDocument(key: 'v')->document->toArray()['paths'])
        ->not->toHaveKey('/api/versioned-forms/archived');
});

/*
 * And the components stay. An unreferenced component is valid, and pruning on the way out would delete a
 * schema an overlay or a consumer's own tooling still names.
 */
it('leaves the components the removed operation was the last reader of', function (): void {
    versioningDiagnostics('tests/Fixtures/Versioning/OperationAdded', route: 'api/versioned-*');

    expect(generateDocument(key: 'v')->document->toArray()['components']['schemas'])->toHaveKey('FormData');
});

it('says so where the selector names no operation this document publishes', function (): void {
    expect(versionedOperationCodes('tests/Fixtures/Versioning/OperationAddedMissing'))->toBe(['versioning.change-target-missing']);
});

it('refuses a selector that names nothing at all', function (): void {
    // An empty selector read as a `*` would take every operation out of the document.
    expect(versionedOperationCodes('tests/Fixtures/Versioning/OperationAddedEmpty'))->toBe(['versioning.change-invalid'])
        ->and(versionedOperations('tests/Fixtures/Versioning/OperationAddedEmpty'))->toBe(versionedOperations(null));
});

/*
 * The order rule, executed. The change renames a parameter of the one operation it also removes: run the
 * removal first and the rename finds no operation declaring the parameter and reports a declaration that
 * is perfectly correct as rotted.
 */
it('applies every other verb before the operation it removes goes', function (): void {
    expect(versionedOperationCodes('tests/Fixtures/Versioning/OperationAddedThenRenamed'))->toBe([])
        ->and(versionedOperations('tests/Fixtures/Versioning/OperationAddedThenRenamed'))->toBe([
            'GET /api/versioned-forms',
            'GET /api/versioned-forms/archived',
        ]);
});
