<?php

declare(strict_types=1);

use Docuccino\Laravel\Support\Psr4Namespaces;

/*
 * The namespace a generated class carries, read off the application's own `composer.json`.
 *
 * Not cosmetic: a version change is found by scanning source and then loading it, so a class whose
 * namespace the autoloader does not map is never applied and nothing says so. Every case here is
 * therefore either a namespace the autoloader would resolve or an explicit null.
 */

/** A base path holding one composer.json, and nothing else. */
function psr4Tree(array $manifest): string
{
    $base = rtrim(sys_get_temp_dir(), '/').'/docuccino-psr4-'.getmypid();

    if (! is_dir($base)) {
        mkdir($base, 0755, true);
    }

    file_put_contents($base.'/composer.json', (string) json_encode($manifest));

    return $base;
}

it('derives the namespace from the prefix covering the directory', function (): void {
    $base = psr4Tree(['autoload' => ['psr-4' => ['App\\' => 'app/']]]);

    expect(Psr4Namespaces::for($base, $base.'/app/Api/Versions'))->toBe('App\\Api\\Versions')
        ->and(Psr4Namespaces::for($base, $base.'/app'))->toBe('App');
});

it('takes the longest matching root, the way composer resolves one', function (): void {
    // A modular application maps a module's own source root as well as the tree above it, and the
    // shorter prefix would put the class in a namespace nothing loads it under.
    $base = psr4Tree(['autoload' => ['psr-4' => [
        'Modules\\' => 'modules/',
        'Modules\\Billing\\' => 'modules/Billing/src/',
    ]]]);

    expect(Psr4Namespaces::for($base, $base.'/modules/Billing/src/Api'))->toBe('Modules\\Billing\\Api')
        ->and(Psr4Namespaces::for($base, $base.'/modules/Other/Api'))->toBe('Modules\\Other\\Api');

    // And the same answer whichever order the two are written in — "longest", not "last read".
    $reversed = psr4Tree(['autoload' => ['psr-4' => [
        'Modules\\Billing\\' => 'modules/Billing/src/',
        'Modules\\' => 'modules/',
    ]]]);

    expect(Psr4Namespaces::for($reversed, $reversed.'/modules/Billing/src/Api'))->toBe('Modules\\Billing\\Api');
});

it('reads a root written with a leading ./ the same as one without', function (): void {
    $base = psr4Tree(['autoload' => ['psr-4' => ['App\\' => './app/']]]);

    expect(Psr4Namespaces::for($base, $base.'/app/Api'))->toBe('App\\Api');
});

it('reads autoload-dev too, since where a prefix is written is not its business', function (): void {
    $base = psr4Tree(['autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']]]);

    expect(Psr4Namespaces::for($base, $base.'/tests/Api/Versions'))->toBe('Tests\\Api\\Versions');
});

it('reads a prefix mapped to several roots', function (): void {
    $base = psr4Tree(['autoload' => ['psr-4' => ['App\\' => ['src/', 'app/']]]]);

    expect(Psr4Namespaces::for($base, $base.'/app/Api'))->toBe('App\\Api')
        ->and(Psr4Namespaces::for($base, $base.'/src/Api'))->toBe('App\\Api');
});

it('maps nothing when nothing covers the directory', function (mixed $manifest): void {
    $base = psr4Tree(is_array($manifest) ? $manifest : []);

    expect(Psr4Namespaces::for($base, $base.'/changes'))->toBeNull();
})->with([
    'an unrelated prefix' => [['autoload' => ['psr-4' => ['App\\' => 'app/']]]],
    'no psr-4 section' => [['autoload' => ['classmap' => ['app/']]]],
    'no autoload section' => [['name' => 'acme/app']],
]);

it('maps nothing for a directory outside the application', function (): void {
    $base = psr4Tree(['autoload' => ['psr-4' => ['App\\' => 'app/']]]);

    expect(Psr4Namespaces::for($base, '/etc/app/Api'))->toBeNull();
});

it('maps nothing when there is no composer.json to read', function (): void {
    // The same answer as "no prefix covers this", and the same remedy — map the directory.
    expect(Psr4Namespaces::for(rtrim(sys_get_temp_dir(), '/').'/docuccino-psr4-absent-'.getmypid(), 'anywhere/Api'))
        ->toBeNull()
        ->and(Psr4Namespaces::roots(rtrim(sys_get_temp_dir(), '/').'/docuccino-psr4-absent-'.getmypid()))
        ->toBe([]);
});

it('maps nothing from a composer.json that is not JSON', function (): void {
    $base = rtrim(sys_get_temp_dir(), '/').'/docuccino-psr4-broken-'.getmypid();

    if (! is_dir($base)) {
        mkdir($base, 0755, true);
    }

    file_put_contents($base.'/composer.json', '{ not json');

    expect(Psr4Namespaces::roots($base))->toBe([])
        ->and(Psr4Namespaces::for($base, $base.'/app'))->toBeNull();

    unlink($base.'/composer.json');
    rmdir($base);
});

it('reports the roots exactly as composer.json writes them', function (): void {
    // The caller normalises: a leading `./` means one thing to "the namespace for this directory" and
    // another to "a directory whose bodies the engine keeps", so this hands back what it read.
    $base = psr4Tree(['autoload' => ['psr-4' => ['App\\' => './app/']], 'autoload-dev' => ['psr-4' => ['App\\' => 'stubs/']]]);

    expect(Psr4Namespaces::roots($base))->toBe(['App\\' => ['./app/', 'stubs/']]);
});

it('reports the shipped roots without the dev ones, and both sections through roots()', function (): void {
    // The two questions one reader answers. Descent may only enter what the application ships, so a
    // `Tests\` root has to be absent from `shipped()` and present in `roots()` — the same file read
    // twice, because two readers of it is two opinions about which directories the analyser walks.
    $base = psr4Tree([
        'autoload' => ['psr-4' => ['App\\' => 'app/', 'Modules\\' => 'modules/']],
        'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
    ]);

    expect(Psr4Namespaces::shipped($base))->toBe(['App\\' => ['app/'], 'Modules\\' => ['modules/']])
        ->and(Psr4Namespaces::roots($base))->toBe([
            'App\\' => ['app/'],
            'Modules\\' => ['modules/'],
            'Tests\\' => ['tests/'],
        ]);
});

it('ships nothing when a composer.json cannot be read', function (): void {
    // The fallback the descend default rests on: no map means no derived scope, and the caller keeps
    // the historical `app/` rather than descending nowhere.
    $absent = rtrim(sys_get_temp_dir(), '/').'/docuccino-psr4-absent-'.getmypid();

    expect(Psr4Namespaces::shipped($absent))->toBe([]);
});

it('ships a prefix a dev section also maps, without folding the dev root in', function (): void {
    // One prefix in both sections is the shape that would silently widen descent if the two maps were
    // merged before the split — the dev root arrives under a key `shipped()` already has.
    $base = psr4Tree([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'autoload-dev' => ['psr-4' => ['App\\' => 'stubs/']],
    ]);

    expect(Psr4Namespaces::shipped($base))->toBe(['App\\' => ['app/']])
        ->and(Psr4Namespaces::roots($base))->toBe(['App\\' => ['app/', 'stubs/']]);
});

it('folds a root segment by segment rather than trimming characters off it', function (string $written, ?string $expected): void {
    // `ltrim($dir, './')` strips a CHARACTER SET, not a `./` prefix — so `.build/src` arrived as
    // `build/src` and `../shared/src` as `shared/src`, each naming a directory the application never
    // mapped and the second one silently relocated inside the base path.
    expect(Psr4Namespaces::relativeRoot($written))->toBe($expected);
})->with([
    'a plain root' => ['app', 'app'],
    'a trailing slash' => ['app/', 'app'],
    'a leading ./' => ['./app/', 'app'],
    'a nested root' => ['modules/Billing/src/', 'modules/Billing/src'],
    'a leading /' => ['/app', 'app'],
    'a dot-prefixed directory' => ['.build/src', '.build/src'],
    'a dot-suffixed directory' => ['src.old/', 'src.old'],
    'an interior . segment' => ['app/./Http', 'app/Http'],
    'an interior .. segment' => ['modules/Billing/../Shared', 'modules/Shared'],
    'the base itself' => ['.', ''],
    'the base as ./' => ['./', ''],
    'the base as an empty string' => ['', ''],
    'the base as a bare slash' => ['/', ''],
    'a parent hop' => ['../shared/src', null],
    'a hop out through a real directory' => ['app/../../escape', null],
]);

it('resolves a namespace under a root written at the package itself', function (): void {
    // A legal map, and PSR-4 really does root `App\app\Http` at `./app/Http` — the caller decides what
    // the base means, which is why the normaliser answers `''` here rather than refusing.
    $base = psr4Tree(['autoload' => ['psr-4' => ['App\\' => './']]]);

    expect(Psr4Namespaces::for($base, $base.'/app/Http'))->toBe('App\\app\\Http');
});

it('does not answer for a directory a dot-prefixed root only looks like it covers', function (): void {
    // `.build/src` and `build/src` are two directories; the trim made them one, and a class scaffolded
    // into the second came out under a namespace nothing loads it from.
    $base = psr4Tree(['autoload' => ['psr-4' => ['Build\\' => '.build/src']]]);

    expect(Psr4Namespaces::for($base, $base.'/.build/src/Api'))->toBe('Build\\Api')
        ->and(Psr4Namespaces::for($base, $base.'/build/src/Api'))->toBeNull();
});
