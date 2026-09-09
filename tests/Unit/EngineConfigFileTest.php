<?php

declare(strict_types=1);

use Docuccino\Laravel\Engine\EngineConfigFile;

/**
 * How `engine.config` is read: a path relative to the application, and — for the fragment-cache key —
 * what the file SAYS rather than where it lives.
 */
it('resolves a configured path against the application base path', function (): void {
    expect(EngineConfigFile::path(['config' => 'phpstan.neon'], '/srv/app'))->toBe('/srv/app/phpstan.neon')
        ->and(EngineConfigFile::path(['config' => '/etc/docuccino/phpstan.neon'], '/srv/app'))->toBe('/etc/docuccino/phpstan.neon');
});

it('has no path to resolve when nothing usable is configured', function (mixed $configured): void {
    expect(EngineConfigFile::path(['config' => $configured], '/srv/app'))->toBeNull();
})->with([
    'absent' => null,
    'empty' => '',
    'a bag where a path belongs' => [['phpstan.neon']],
    'a bool' => true,
]);

it('digests what the file says, so an edited extension moves the key', function (): void {
    $root = sys_get_temp_dir().'/docuccino-neon-'.uniqid('', true);
    mkdir($root, 0o755, true);
    file_put_contents($root.'/phpstan.neon', "parameters:\n    level: 9\n");

    $digest = EngineConfigFile::digest(['config' => 'phpstan.neon'], $root);

    file_put_contents($root.'/phpstan.neon', "parameters:\n    level: 8\n");
    $edited = EngineConfigFile::digest(['config' => 'phpstan.neon'], $root);

    expect($digest)->not->toBe('')
        ->and($edited)->not->toBe($digest);

    @unlink($root.'/phpstan.neon');
    @rmdir($root);
});

it('digests to nothing when there is no file to read', function (): void {
    // Configured-but-absent and not-configured-at-all both come back empty; the configured PATH is
    // what tells those two apart, and it travels in the engine config bag.
    expect(EngineConfigFile::digest([], base_path()))->toBe('')
        ->and(EngineConfigFile::digest(['config' => 'nothing-here.neon'], base_path()))->toBe('');
});
