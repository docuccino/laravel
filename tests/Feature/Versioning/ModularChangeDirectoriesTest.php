<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Illuminate\Routing\Router;
use Workbench\App\Http\Controllers\VersionedFormController;

/**
 * Version changes read out of several directories at once — the modular application, whose changes
 * belong beside the modules that own them rather than in one folder under `app/`.
 *
 * The fixture is a chain split across two of them ON PURPOSE: the older half sits in the entry read
 * FIRST and the newer half in the module read LAST, so a set ordered by configuration entry, by
 * directory or by whatever the filesystem handed over applies them the wrong way round and the older
 * half finds nothing to rename. What decides the order is `since` and nothing else.
 */
beforeEach(function (): void {
    app()->setBasePath(dirname(__DIR__, 3));
    bindStubEngine();

    /** @var Router $router */
    $router = app('router');
    $router->get('api/versioned-forms', [VersionedFormController::class, 'index']);
});

/** The application's own change directory, read first — and holding the half of the chain applied last. */
function modularAppEntry(): string
{
    return 'tests/Fixtures/Versioning/Modular/App/Api/Versions';
}

/** One entry standing for every module, `Alpha` and `Zebra` both. */
function modularModuleGlob(): string
{
    return 'tests/Fixtures/Versioning/Modular/Modules/*/Api/Versions';
}

/**
 * The `FormData` the older version publishes with `$changes` configured, and the versioning
 * diagnostics raised getting there.
 *
 * @param  list<string>  $changes
 * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
 */
function modularFormSchema(array $changes): array
{
    config()->set('docuccino.documents', ['v' => [
        'info' => ['title' => 'Forms API', 'version' => '2026-06-01'],
        'routes' => ['include' => ['api/versioned-forms']],
        'error_responses' => 'none',
        'api_version' => ['changes' => $changes],
    ]]);

    $result = generateDocument(key: 'v');

    /** @var array<string, mixed> $schema */
    $schema = $result->document->toArray()['components']['schemas']['FormData'];

    return [$schema, array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => str_starts_with($diagnostic->code, 'versioning.'),
    ))];
}

it('reads a change out of every configured directory, and one glob entry out of every module', function (): void {
    // Three changes, three directories, one of the entries a glob standing for two modules: the chain
    // spans `App` and `Modules/Zebra`, and `Modules/Alpha` proves the glob reached past the first match.
    [$schema, $diagnostics] = modularFormSchema([modularAppEntry(), modularModuleGlob()]);

    expect($diagnostics)->toBe([])
        ->and(array_keys($schema['properties']))->toBe(['id', 'name', 'publishedAt', 'subtotal'])
        ->and($schema['required'])->toBe(['id', 'name']);
});

it('applies them in version order, not in the order the directories were enumerated', function (): void {
    // The executed guard. Enumeration order here is App then Modules/Alpha then Modules/Zebra, which is
    // the exact REVERSE of the order the three have to apply in — so a walk that inherited it would
    // rename `name` before anything had put `label` back, leave `title` standing, and report the
    // perfectly correct declaration as rotted.
    [$schema, $diagnostics] = modularFormSchema([modularAppEntry(), modularModuleGlob()]);

    expect($diagnostics)->toBe([])
        ->and($schema['properties'])->toHaveKey('name')
        ->and($schema['properties'])->not->toHaveKey('title')
        ->and($schema['properties'])->not->toHaveKey('label');
});

it('publishes the same bytes however the entries are written', function (): void {
    // Swapping the two entries swaps which directory is read first and which module is met first, and
    // the document may not notice: the order the changes apply in is a function of their versions.
    [$written] = modularFormSchema([modularAppEntry(), modularModuleGlob()]);
    [$swapped] = modularFormSchema([modularModuleGlob(), modularAppEntry()]);

    expect(json_encode($swapped))->toBe(json_encode($written));
});

it('reports the entry that named nothing, and reads the ones that did', function (): void {
    // Per entry, because a modular application adding one bad line should hear about that line rather
    // than lose the changes every other entry resolved.
    [$schema, $diagnostics] = modularFormSchema([
        modularAppEntry(),
        modularModuleGlob(),
        'tests/Fixtures/Versioning/Modular/Modules/*/Nowhere',
    ]);

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.dir-missing')
        ->and($diagnostics[0]->message)->toContain('Modules/*/Nowhere')
        ->and($diagnostics[0]->help)->toContain('api_version.changes')
        ->and(array_keys($schema['properties']))->toBe(['id', 'name', 'publishedAt', 'subtotal']);
});

it('refuses an entry that names a path outside the application, and reads the ones that do not', function (): void {
    [$schema, $diagnostics] = modularFormSchema(['../../../etc', modularAppEntry(), modularModuleGlob()]);

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe('versioning.dir-escapes-base')
        ->and(array_keys($schema['properties']))->toBe(['id', 'name', 'publishedAt', 'subtotal']);
});
