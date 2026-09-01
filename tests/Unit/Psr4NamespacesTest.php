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
