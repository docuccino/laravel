<?php

declare(strict_types=1);

use Docuccino\Core\Support\Glob;
use Docuccino\Laravel\Versioning\Scaffold\ChangePlacement;
use Workbench\App\Data\FormData;
use Workbench\App\Http\Controllers\VersionedFormController;

/*
 * Where a scaffolded change is written, and why there.
 *
 * The classes are real and so are their files: the whole rule turns on a class's own declaration file
 * against the module roots the configuration declared, so a test that handed it invented paths would
 * prove only that string comparison works. The module map is supplied directly because that is what
 * `ChangeDirectories` hands over — its own half of the reading has its own test.
 */

/** The application root these tests read module roots under: the adapter package. */
function placementBase(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @param  array<string, string>  $modules  directory => module root, relative to the base
 * @param  list<string>  $extra  further configured directories, first one first
 */
function placementFor(string $fqcn, array $modules, array $extra = ['changes'], ?string $forced = null): string
{
    $base = placementBase();

    $absolute = [];
    foreach ($modules as $directory => $root) {
        $absolute[$base.'/'.$directory] = $base.'/'.$root;
    }

    $directories = [...array_map(static fn (string $one): string => $base.'/'.$one, $extra), ...array_keys($absolute)];

    $destination = (new ChangePlacement($base, array_values($directories), $absolute, $forced))->for($fqcn);

    return $destination->reason === ''
        ? $destination->directory
        : substr($destination->directory, strlen($base) + 1).' — '.$destination->reason;
}

it('writes a change beside the module whose declared root holds the class', function (): void {
    expect(placementFor(FormData::class, ['workbench/app/Data/Versions' => 'workbench/app/Data']))
        ->toBe('workbench/app/Data/Versions — beside workbench/app/Data, which owns Workbench\\App\\Data\\FormData');
});

it('gives each module its own change when one diff spans two of them', function (): void {
    // The two-module case, and there is nothing to refuse in it: a change names exactly ONE class, so
    // each goes beside its own module and neither is dropped. What no single module can answer is one
    // CLASS two of them claim, which is the tie below.
    $modules = [
        'workbench/app/Data/Versions' => 'workbench/app/Data',
        'workbench/app/Http/Versions' => 'workbench/app/Http',
    ];

    expect(placementFor(FormData::class, $modules))->toStartWith('workbench/app/Data/Versions —')
        ->and(placementFor(VersionedFormController::class, $modules))->toStartWith('workbench/app/Http/Versions —');
});

it('gives the longest declared root the class, so a module inside a module wins it', function (): void {
    expect(placementFor(FormData::class, [
        'workbench/app/Versions' => 'workbench/app',
        'workbench/app/Data/Versions' => 'workbench/app/Data',
    ]))->toStartWith('workbench/app/Data/Versions —');
});

it('refuses to choose between two roots that claim the class equally, and says which', function (): void {
    // A tie broken by glob enumeration order would be a destination that moved when an unrelated
    // sibling module was added — deterministic per build, and still a different answer next week.
    expect(placementFor(FormData::class, [
        'workbench/app/Data/Api/Versions' => 'workbench/app/Data',
        'workbench/app/Data/Http/Versions' => 'workbench/app/Data',
    ]))->toBe('changes — the first configured change directory; workbench/app/Data/Api/Versions and workbench/app/Data/Http/Versions claim Workbench\\App\\Data\\FormData equally, so nothing here can choose');
});

it('falls back to the first configured directory for a class no module holds', function (): void {
    expect(placementFor(Glob::class, ['workbench/app/Data/Versions' => 'workbench/app/Data']))
        ->toBe('changes — the first configured change directory; no configured module holds Docuccino\\Core\\Support\\Glob');
});

it('says there was only one place to write when the configuration declared no boundary', function (): void {
    expect(placementFor(FormData::class, [], ['changes']))->toBe('changes — the only configured change directory');
});

it('lets --in override the module that owns the class', function (): void {
    // An instruction, not evidence: the flag exists precisely for the run where the author knows better
    // than the layout their config declares.
    expect(placementFor(
        FormData::class,
        ['workbench/app/Data/Versions' => 'workbench/app/Data'],
        forced: placementBase().'/changes',
    ))->toBe('changes — you named it with --in');
});

it('falls back for a class nothing can read a file for', function (): void {
    // A pinned `#[SchemaId]` never reaches this far, but a class the autoloader lost between the build
    // and the write would: no file, no module, and the answer is still a directory rather than a crash.
    expect(placementFor('Docuccino\\Nope\\NeverDeclared', ['workbench/app/Data/Versions' => 'workbench/app/Data']))
        ->toBe('changes — the first configured change directory; no configured module holds Docuccino\\Nope\\NeverDeclared');
});
