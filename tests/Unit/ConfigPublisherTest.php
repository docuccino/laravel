<?php

declare(strict_types=1);

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Laravel\Config\ConfigPublisher;
use Docuccino\Laravel\Config\ConfigPublishers;
use Docuccino\Laravel\DocuccinoServiceProvider;
use Illuminate\Support\ServiceProvider;

/**
 * The one write the package makes outside an export path. It has to be a byte copy — the shipped file
 * is the documented surface, and a publisher that reformatted it would ship a different config than
 * the reference describes — and it must fail rather than half-write, so an interrupted install never
 * leaves a truncated `config/docuccino.php` for the app to boot.
 */
it('copies the shipped file byte for byte, creating the directory it needs', function (): void {
    $target = sys_get_temp_dir().'/docuccino-publisher-'.bin2hex(random_bytes(8)).'/config/docuccino.php';
    $source = dirname(__DIR__, 2).'/config/docuccino.php';
    $publisher = new ConfigPublisher($source, $target);

    expect($publisher->target())->toBe($target)
        ->and($publisher->published())->toBeFalse()
        ->and($publisher->publish())->toBeTrue()
        ->and($publisher->published())->toBeTrue()
        ->and(file_get_contents($target))->toBe(file_get_contents($source));

    unlink($target);
    rmdir(dirname($target));
    rmdir(dirname($target, 2));
});

it('overwrites an existing file when asked to publish again', function (): void {
    $directory = sys_get_temp_dir().'/docuccino-publisher-'.bin2hex(random_bytes(8));
    mkdir($directory, 0755, true);
    $target = $directory.'/docuccino.php';
    file_put_contents($target, 'stale');

    $source = dirname(__DIR__, 2).'/config/docuccino.php';

    expect((new ConfigPublisher($source, $target))->publish())->toBeTrue()
        ->and(file_get_contents($target))->toBe(file_get_contents($source));

    unlink($target);
    rmdir($directory);
});

it('reports a failure instead of writing half a file', function (string $source, string $target): void {
    expect((new ConfigPublisher($source, $target))->publish())->toBeFalse()
        ->and(file_exists($target))->toBeFalse();
})->with([
    'unreadable source' => [
        fn (): string => sys_get_temp_dir().'/docuccino-publisher-missing-'.bin2hex(random_bytes(8)).'.php',
        fn (): string => sys_get_temp_dir().'/docuccino-publisher-unwritten-'.bin2hex(random_bytes(8)).'.php',
    ],
    'undirectory-able target' => [
        fn (): string => dirname(__DIR__, 2).'/config/docuccino.php',
        fn (): string => '/dev/null/docuccino/docuccino.php',
    ],
]);

/*
 * And `vendor:publish` writes the same configuration `docuccino:install` does.
 *
 * Asserted rather than assumed because the two are separate mechanisms with one job: the framework's
 * config file rides `hasConfigFile()`, the build configuration is registered beside it, and the
 * command has its own list. A tag that copied one of the two would hand an author viewer wiring and
 * nothing that shapes a document — and the way to discover the build surface is to read the file.
 */
it('publishes both configuration files under the docuccino-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(DocuccinoServiceProvider::class, 'docuccino-config');
    $package = dirname(__DIR__, 2);

    // Keyed by SOURCE, and the two registrations spell their own package path differently — spatie's
    // `hasConfigFile()` resolves it through `src/..`. So the sources are checked as the files they
    // resolve to and the targets as the literals an author ends up with.
    expect(array_map(realpath(...), array_keys($paths)))->toBe([
        $package.'/config/docuccino.php',
        $package.'/config/'.ConfigFile::NAME,
    ])
        ->and(array_values($paths))->toBe([
            config_path('docuccino.php'),
            base_path(ConfigFile::NAME),
        ]);
});

it('writes the same targets the install command does', function (): void {
    // The invariant behind the pair, stated over the two lists rather than over either one: an author
    // who publishes and an author who installs have to end up with the same two files.
    $published = array_values(ServiceProvider::pathsToPublish(DocuccinoServiceProvider::class, 'docuccino-config'));
    $installed = array_map(
        static fn (ConfigPublisher $publisher): string => $publisher->target(),
        app(ConfigPublishers::class)->all(),
    );

    sort($published);
    sort($installed);

    expect($installed)->toHaveCount(2)->and($published)->toBe($installed);
});
