<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\ConfigPublisher;

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
