<?php

declare(strict_types=1);

use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Engine\LazyTypeEngine;
use Docuccino\Laravel\Engine\TypeEngineFactory;
use Docuccino\Laravel\Pipeline\BuildFingerprint;
use Docuccino\Laravel\Support\Psr4Namespaces;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * The build-environment half of the fragment-cache key: everything about the engine and the app's
 * locked dependencies that changes emitted bytes without changing a file the analysis read — and the
 * one engine option deliberately left out of it.
 *
 * @param  array<string, mixed>  $engineConfig
 */
function buildFingerprint(array $engineConfig = [], ?EnginePackage $package = null, string $basePath = ''): BuildFingerprint
{
    return new BuildFingerprint($engineConfig, $basePath, $package ?? new EnginePackage);
}

it('is stable for an unchanged environment', function (): void {
    $engine = new NullTypeEngine;

    expect(buildFingerprint(['mode' => 'in-process'])->digest($engine))
        ->toBe(buildFingerprint(['mode' => 'in-process'])->digest($engine));
});

it('changes when the engine package appears', function (): void {
    $absent = new EnginePackage(static fn (string $class): bool => false);
    $present = new EnginePackage(static fn (string $class): bool => true);
    $engine = new NullTypeEngine;

    expect(buildFingerprint([], $absent)->digest($engine))->not->toBe(buildFingerprint([], $present)->digest($engine));
});

it('changes when the engine that resolved changes', function (): void {
    $fingerprint = buildFingerprint();

    expect($fingerprint->digest(new NullTypeEngine))->not->toBe($fingerprint->digest(WorkbenchEngine::make()));
});

it('reads a deferred engine by name, without booting it', function (): void {
    $fingerprint = buildFingerprint();
    $lazy = new LazyTypeEngine(
        static fn (): TypeEngine => throw new RuntimeException('the fragment-cache key booted the engine'),
        WorkbenchEngine::make()::class,
    );

    // Identical to the key the same engine would produce if it had been built eagerly (see
    // LazyTypeEngine): deferring the boot must not move one fragment.
    expect($fingerprint->digest($lazy))->toBe($fingerprint->digest(WorkbenchEngine::make()));
});

it('still moves when the engine package appears behind a deferred engine', function (): void {
    $absent = new EnginePackage(static fn (string $class): bool => false);
    $present = new EnginePackage(static fn (string $class): bool => true);

    $lazy = static function (EnginePackage $package): LazyTypeEngine {
        $factory = new TypeEngineFactory(basePath: base_path(), tmpDir: storage_path('docuccino'), engine: $package);

        return new LazyTypeEngine(
            static fn (): TypeEngine => throw new RuntimeException('the fragment-cache key booted the engine'),
            $factory->engineIdentity(['mode' => 'in-process']),
        );
    };

    expect(buildFingerprint([], $absent)->digest($lazy($absent)))
        ->not->toBe(buildFingerprint([], $present)->digest($lazy($present)));
});

it('changes with an output-shaping engine option', function (string $key, mixed $value): void {
    $engine = new NullTypeEngine;

    expect(buildFingerprint(['mode' => 'in-process', 'project_paths' => ['app']])->digest($engine))
        ->not->toBe(buildFingerprint(['mode' => 'in-process', 'project_paths' => ['app'], $key => $value])->digest($engine));
})->with([
    'mode' => ['mode', 'null'],
    'project_paths' => ['project_paths', ['app', 'modules']],
]);

/**
 * The one input `composer.lock` cannot stand in for. Composer's content hash does not cover `autoload`,
 * so mapping a new PSR-4 root and running `dump-autoload` moves no locked byte — while it moves both
 * scopes the engine runs with, since prime scope IS that map and descent defaults to the shipped half of
 * it. A key blind to it would serve a warm build fragments from a scope the application no longer has.
 */
it('changes when the application maps a source root it did not map before', function (): void {
    $engine = new NullTypeEngine;
    $root = sys_get_temp_dir().'/docuccino-psr4-key-'.uniqid('', true);
    mkdir($root, 0o755, true);

    $write = static function (array $manifest) use ($root): void {
        file_put_contents($root.'/composer.json', (string) json_encode($manifest));
    };

    $write(['autoload' => ['psr-4' => ['App\\' => 'app/']]]);
    $before = buildFingerprint(['mode' => 'in-process'], basePath: $root)->digest($engine);

    // A modular root added, and the lockfile untouched — which is exactly what `dump-autoload` does.
    $write(['autoload' => ['psr-4' => ['App\\' => 'app/', 'Modules\\' => 'modules/']]]);
    $after = buildFingerprint(['mode' => 'in-process'], basePath: $root)->digest($engine);

    // And a dev root moves it too: prime scope reads both sections, so a body that stops being kept
    // intact is the same kind of change.
    $write([
        'autoload' => ['psr-4' => ['App\\' => 'app/', 'Modules\\' => 'modules/']],
        'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
    ]);
    $withDev = buildFingerprint(['mode' => 'in-process'], basePath: $root)->digest($engine);

    expect($after)->not->toBe($before)
        ->and($withDev)->not->toBe($after)
        // Unchanged bytes, unchanged key — the other direction, or the assertions above pass on noise.
        ->and(buildFingerprint(['mode' => 'in-process'], basePath: $root)->digest($engine))->toBe($withDev);

    unlink($root.'/composer.json');
    rmdir($root);
});

/**
 * The two scopes read different halves of one map, so the key has to be injective over the halves and
 * not just over their merge. Moving a root from `autoload-dev` into `autoload` leaves the merge
 * identical and widens what descent may walk into — a warm build under an unchanged digest would keep
 * publishing the narrower scope's error responses, which is the exact invariant this input was added
 * for.
 */
it('changes when a root moves between the autoload sections, leaving the merge identical', function (): void {
    $engine = new NullTypeEngine;
    $root = sys_get_temp_dir().'/docuccino-psr4-sections-'.uniqid('', true);
    mkdir($root, 0o755, true);

    $write = static function (array $manifest) use ($root): void {
        file_put_contents($root.'/composer.json', (string) json_encode($manifest));
    };

    $write([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'autoload-dev' => ['psr-4' => ['Modules\\' => 'modules/']],
    ]);
    $asDev = buildFingerprint(['mode' => 'in-process'], basePath: $root)->digest($engine);

    $write([
        'autoload' => ['psr-4' => ['App\\' => 'app/', 'Modules\\' => 'modules/']],
        'autoload-dev' => ['psr-4' => []],
    ]);
    $asShipped = buildFingerprint(['mode' => 'in-process'], basePath: $root)->digest($engine);

    // Both maps merge to the same two roots, so prime scope really is unchanged — which is what makes
    // this a test of the descend half rather than of the merge.
    expect(Psr4Namespaces::roots($root))->toBe(['App\\' => ['app/'], 'Modules\\' => ['modules/']])
        ->and($asShipped)->not->toBe($asDev);

    unlink($root.'/composer.json');
    rmdir($root);
});

it('follows what the user neon SAYS, not just where it points', function (): void {
    // An extension registered in that file can change any type the engine infers, and editing it
    // moves no analysed file and no config value — so the path alone would serve stale fragments.
    $engine = new NullTypeEngine;
    $root = sys_get_temp_dir().'/docuccino-neon-key-'.uniqid('', true);
    mkdir($root, 0o755, true);
    $config = ['mode' => 'in-process', 'config' => 'phpstan.neon'];

    $unconfigured = buildFingerprint(['mode' => 'in-process'], basePath: $root)->digest($engine);
    // Configured but not yet written: the key already moved, because the path is in the config bag.
    $configured = buildFingerprint($config, basePath: $root)->digest($engine);

    file_put_contents($root.'/phpstan.neon', "services:\n");
    $written = buildFingerprint($config, basePath: $root)->digest($engine);

    file_put_contents($root.'/phpstan.neon', "services:\n    - App\\Docs\\OrderTotalExtension\n");
    $edited = buildFingerprint($config, basePath: $root)->digest($engine);

    expect($unconfigured)->not->toBe($configured)
        ->and($configured)->not->toBe($written)
        ->and($written)->not->toBe($edited);

    @unlink($root.'/phpstan.neon');
    @rmdir($root);
});

it('ignores the memory limit, which cannot change a documented byte', function (): void {
    $engine = new NullTypeEngine;

    expect(buildFingerprint(['mode' => 'in-process'])->digest($engine))
        ->toBe(buildFingerprint(['mode' => 'in-process', 'memory_limit' => '2G'])->digest($engine));
});

it('follows the app composer.lock, and survives an app that has none', function (): void {
    $engine = new NullTypeEngine;
    $root = sys_get_temp_dir().'/docuccino-lock-'.uniqid('', true);
    mkdir($root, 0755, true);

    // No lock file yet: the digest is total, it just carries nothing for the vendor half.
    $lockless = buildFingerprint(basePath: $root)->digest($engine);

    file_put_contents($root.'/composer.lock', '{"packages":[{"name":"larastan/larastan","version":"v3.0.0"}]}');
    $locked = buildFingerprint(basePath: $root)->digest($engine);

    file_put_contents($root.'/composer.lock', '{"packages":[{"name":"larastan/larastan","version":"v3.1.0"}]}');
    $upgraded = buildFingerprint(basePath: $root)->digest($engine);

    expect($lockless)->not->toBe($locked)
        ->and($locked)->not->toBe($upgraded);

    @unlink($root.'/composer.lock');
    @rmdir($root);
});
