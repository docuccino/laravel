<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Docuccino\Laravel\Support\Psr4ClassFile;

/**
 * Where a class WOULD be written. The point of the answer is that it is given for classes that do NOT
 * exist — that is what lets a build key a fragment on a policy the application has not created yet — so
 * every row here names a file that is not there.
 */
function psr4Loader(): ClassLoader
{
    foreach (spl_autoload_functions() as $autoloader) {
        if (is_array($autoloader) && ($autoloader[0] ?? null) instanceof ClassLoader) {
            return $autoloader[0];
        }
    }

    throw new RuntimeException('no Composer autoloader registered');
}

it('answers where a class the application has not written would go', function (): void {
    $prefix = 'DocuccinoPsr4Probe'.dechex(random_int(0, PHP_INT_MAX)).'\\';
    psr4Loader()->addPsr4($prefix, ['/srv/app/src', '/srv/app/extra']);

    $candidates = Psr4ClassFile::candidates($prefix.'Policies\\WidgetPolicy');

    expect(class_exists($prefix.'Policies\\WidgetPolicy'))->toBeFalse()
        ->and($candidates)->toBe([
            '/srv/app/extra/Policies/WidgetPolicy.php',
            '/srv/app/src/Policies/WidgetPolicy.php',
        ]);
});

it('answers the same whatever order the directories were registered in', function (): void {
    // Recorded as cache dependencies, so the list is part of what a warm build compares against.
    $one = 'DocuccinoPsr4Order'.dechex(random_int(0, PHP_INT_MAX)).'\\';
    $two = 'DocuccinoPsr4Order'.dechex(random_int(0, PHP_INT_MAX)).'\\';
    psr4Loader()->addPsr4($one, ['/srv/a', '/srv/b']);
    psr4Loader()->addPsr4($two, ['/srv/b', '/srv/a']);

    expect(Psr4ClassFile::candidates($one.'Thing'))->toBe(['/srv/a/Thing.php', '/srv/b/Thing.php'])
        ->and(Psr4ClassFile::candidates($two.'Thing'))->toBe(['/srv/a/Thing.php', '/srv/b/Thing.php']);
});

it('reads every registered Composer loader, not just the first one it finds', function (): void {
    // An application that registers a second loader — a package's own, a test harness's — had half its
    // map read, and half a map is a fragment keyed on paths the class is not at.
    $only = 'DocuccinoPsr4Second'.dechex(random_int(0, PHP_INT_MAX)).'\\';
    $shared = 'DocuccinoPsr4Shared'.dechex(random_int(0, PHP_INT_MAX)).'\\';
    $second = new ClassLoader;
    $second->addPsr4($only, ['/srv/second/src']);
    $second->addPsr4($shared, ['/srv/second/src']);
    psr4Loader()->addPsr4($shared, ['/srv/first/src']);
    $second->register();

    try {
        expect(Psr4ClassFile::candidates($only.'Widget'))->toBe(['/srv/second/src/Widget.php'])
            // Merged rather than shadowed: one prefix in two loaders means both its directories.
            ->and(Psr4ClassFile::candidates($shared.'Widget'))
            ->toBe(['/srv/first/src/Widget.php', '/srv/second/src/Widget.php']);
    } finally {
        $second->unregister();
    }
});

it('says nothing about a class no PSR-4 prefix covers', function (): void {
    expect(Psr4ClassFile::candidates('NothingMapsThis\\AtAll\\Widget'))->toBe([]);
});

it('reads the map the application really autoloads with', function (): void {
    // Anti-vacuity for the rows above, which register their own prefixes: this one asks about a class
    // that is genuinely loaded here, and the answer has to be the file it is loaded from.
    $file = (new ReflectionClass(Psr4ClassFile::class))->getFileName();
    $resolved = array_map(realpath(...), Psr4ClassFile::candidates(Psr4ClassFile::class));

    expect($resolved)->toContain(realpath((string) $file));
});
