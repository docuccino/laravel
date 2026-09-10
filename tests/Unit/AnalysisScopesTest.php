<?php

declare(strict_types=1);

use Docuccino\Laravel\Engine\AnalysisScopes;

/*
 * The two directory sets the analyser is handed, and the one difference between them that matters:
 * PRIME keeps every root the application maps readable, DESCEND is what the analysis may walk into.
 *
 * The default descend scope is derived, so these are the cases a real application's composer.json takes.
 * The one that has to be provable is the stock shape, because that is the population the change must not
 * move — and it is NOT `['app']`: a Laravel skeleton also maps the two database roots, so the derived set
 * is three directories. Measured on the fixture corpus, the two extra ones change nothing at all — same
 * file walks, same peak memory, same answers — which is the reason the wider default is safe there.
 */

/** A base path with a composer.json and the directories it maps really present. */
function scopeTree(array $manifest, array $directories = []): string
{
    $base = rtrim(sys_get_temp_dir(), '/').'/docuccino-scopes-'.bin2hex(random_bytes(6));
    mkdir($base, 0755, true);
    file_put_contents($base.'/composer.json', (string) json_encode($manifest));

    foreach ($directories as $directory) {
        mkdir($base.'/'.$directory, 0755, true);
    }

    return $base;
}

it('derives a stock application\'s scope from the skeleton\'s own map', function (): void {
    // The common case, written out as the Laravel 12 skeleton actually maps it rather than as `app/`
    // alone — which is the shape the shipped default used to pin. So the derived set here is three
    // roots, and the two extra ones are measurably free: descending into them costs the fixture corpus
    // 0 extra file walks, 0 MB and not one changed answer, because nothing an action calls is written
    // in a factory or a seeder. That is what makes the default safe to change for everybody who never
    // had a modular root in the first place.
    $base = scopeTree([
        'autoload' => ['psr-4' => [
            'App\\' => 'app/',
            'Database\\Factories\\' => 'database/factories/',
            'Database\\Seeders\\' => 'database/seeders/',
        ]],
        'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
    ], ['app', 'database/factories', 'database/seeders', 'tests']);

    expect((new AnalysisScopes($base))->descend([]))
        ->toBe([$base.'/app', $base.'/database/factories', $base.'/database/seeders']);
});

it('descends into every root the application ships, dev roots excluded', function (): void {
    $base = scopeTree([
        'autoload' => ['psr-4' => ['App\\' => 'app/', 'Modules\\' => 'modules/', 'Domain\\' => './domain/']],
        'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
    ], ['app', 'modules', 'domain', 'tests']);

    $scopes = new AnalysisScopes($base);

    // A modular root descends; a test root does not, and still has to be readable.
    expect($scopes->descend([]))->toBe([$base.'/app', $base.'/modules', $base.'/domain'])
        ->and($scopes->prime($scopes->descend([])))
        ->toBe([$base.'/app', $base.'/modules', $base.'/domain', $base.'/tests']);
});

it('leaves out a declared root that is not on disk', function (): void {
    // A map naming a directory nobody created would hand PHPStan a path it cannot walk. The remaining
    // roots still descend — a stale entry is not a reason to stop analysing the rest.
    $base = scopeTree(
        ['autoload' => ['psr-4' => ['App\\' => 'app/', 'Gone\\' => 'gone/']]],
        ['app'],
    );

    expect((new AnalysisScopes($base))->descend([]))->toBe([$base.'/app']);
});

it('keeps app/ when there is no autoload map to derive from', function (mixed $manifest): void {
    // Descending nowhere would drop every interprocedural fact at once, which is a much worse answer
    // than descending where a Laravel application keeps its code.
    $base = is_array($manifest)
        ? scopeTree($manifest, ['app'])
        : rtrim(sys_get_temp_dir(), '/').'/docuccino-scopes-absent-'.bin2hex(random_bytes(6));

    expect((new AnalysisScopes($base))->descend([]))->toBe([$base.'/app']);
})->with([
    'no autoload section' => [['name' => 'acme/app']],
    'a classmap and no psr-4' => [['autoload' => ['classmap' => ['app/']]]],
    'no composer.json at all' => [null],
]);

it('takes a configured list over the derived one, exactly as written', function (): void {
    // A directory that is not there yet narrows nothing, so a configured path is NOT filtered against
    // the filesystem: dropping it would silently hand the build a scope the reader never asked for.
    $base = scopeTree(['autoload' => ['psr-4' => ['App\\' => 'app/', 'Modules\\' => 'modules/']]], ['app', 'modules']);

    expect((new AnalysisScopes($base))->descend(['project_paths' => ['app', '/src', 'later/']]))
        ->toBe([$base.'/app', $base.'/src', $base.'/later/']);
});

it('falls back to the derived set for a project_paths that names nothing', function (mixed $configured): void {
    // An empty list, or one holding no strings, is not a narrowing anybody meant.
    $base = scopeTree(['autoload' => ['psr-4' => ['App\\' => 'app/', 'Modules\\' => 'modules/']]], ['app', 'modules']);

    expect((new AnalysisScopes($base))->descend(['project_paths' => $configured]))
        ->toBe([$base.'/app', $base.'/modules']);
})->with([
    'an empty list' => [[]],
    'nothing but non-strings' => [[1, 2.5, true]],
    'not a list at all' => ['app'],
    'null' => [null],
]);

it('primes a configured descend path even where the map does not name it', function (): void {
    // Prime is the union: a build told to descend somewhere has to be able to read it, and the map
    // alone would leave that directory body-stripped.
    $base = scopeTree(['autoload' => ['psr-4' => ['App\\' => 'app/']]], ['app', 'extra']);

    $scopes = new AnalysisScopes($base);
    $descend = $scopes->descend(['project_paths' => ['extra']]);

    expect($descend)->toBe([$base.'/extra'])
        ->and($scopes->prime($descend))->toBe([$base.'/extra', $base.'/app']);
});

it('refuses a root written at the base itself, which would put vendor inside both scopes', function (string $mapped): void {
    // `{"autoload":{"psr-4":{"App\\":"./"}}}` is a legal map and the one root neither scope may take:
    // `vendor/` sits under the base, so descent would stop treating a dependency as a terminal and
    // start promoting its `@throws` into a published response, and priming would hand PHPStan the
    // whole tree to keep intact. The other roots still answer.
    $base = scopeTree(
        ['autoload' => ['psr-4' => ['App\\' => $mapped, 'Modules\\' => 'modules/']]],
        ['app', 'modules', 'vendor'],
    );

    $scopes = new AnalysisScopes($base);

    expect($scopes->descend([]))->toBe([$base.'/modules'])
        ->and($scopes->prime($scopes->descend([])))->toBe([$base.'/modules']);
})->with([
    'a bare dot' => ['.'],
    'dot slash' => ['./'],
    'an empty string' => [''],
    'a bare slash' => ['/'],
]);

it('falls back to app/ when the base itself is the only root the map names', function (): void {
    // Nothing survives, so this is the same answer as an unreadable composer.json — and emphatically
    // not the base path, which is what a character-set trim collapsed it to.
    $base = scopeTree(['autoload' => ['psr-4' => ['App\\' => './']]], ['app', 'vendor']);

    expect((new AnalysisScopes($base))->descend([]))->toBe([$base.'/app']);
});

it('refuses a root that climbs out of the base', function (string $mapped): void {
    // A file outside the base has no root-relative name, so a diagnostic naming one would print a path
    // off the build machine. `../shared/src` used to arrive as `shared/src` — a directory INSIDE the
    // base that the map never named, which is why the relocated directory is on disk here.
    $base = scopeTree(['autoload' => ['psr-4' => ['App\\' => 'app/', 'Shared\\' => $mapped]]], ['app', 'shared/src']);

    expect((new AnalysisScopes($base))->descend([]))->toBe([$base.'/app']);
})->with([
    'a parent hop' => ['../shared/src'],
    'a hop out and back through a real directory' => ['app/../../escape'],
]);

it('keeps a root whose own directory name starts with a dot', function (): void {
    // Two directories, and the trim made them one: the generated tree was scanned and the declared one
    // never was.
    $base = scopeTree(['autoload' => ['psr-4' => ['Build\\' => '.build/src']]], ['.build/src', 'build/src']);

    expect((new AnalysisScopes($base))->descend([]))->toBe([$base.'/.build/src']);
});
