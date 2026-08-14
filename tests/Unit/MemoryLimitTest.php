<?php

declare(strict_types=1);

use Docuccino\Laravel\Commands\MemoryLimitOption;
use Docuccino\Laravel\Engine\ConsoleBuild;
use Docuccino\Laravel\Engine\EnginePackage;
use Docuccino\Laravel\Engine\MemoryLimit;
use Docuccino\Laravel\Engine\OutOfMemoryNotice;
use Docuccino\Laravel\Engine\TypeEngineFactory;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * In-process inference is bound by the calling process's memory_limit, and exhausting it is an uncatchable
 * fatal — so the knob has to be honest about what it does. It only raises: a configured ceiling that isn't
 * higher than the one in force, and an already-unlimited process, are both left alone.
 */
it('reads every php.ini shorthand a memory limit can use', function (string $value, ?int $bytes): void {
    expect(MemoryLimit::bytes($value))->toBe($bytes);
})->with([
    'plain bytes' => ['1048576', 1048576],
    'kilobytes' => ['1024K', 1048576],
    'megabytes' => ['512M', 536870912],
    'gigabytes' => ['2G', 2147483648],
    'lower-case suffix' => ['2g', 2147483648],
    'surrounding whitespace' => ['  256M  ', 268435456],
    // -1 is unlimited, so it has to compare as the largest ceiling there is.
    'unlimited' => ['-1', PHP_INT_MAX],
    // Anything PHP itself wouldn't accept degrades rather than being guessed at.
    'empty' => ['', null],
    'a bare suffix' => ['G', null],
    'a float' => ['1.5G', null],
    'an unknown suffix' => ['2T', null],
    'a negative that is not -1' => ['-2', null],
    'words' => ['lots', null],
    // A figure that would saturate the cast or overflow the scaling is unreadable, not enormous.
    'digits beyond an int' => ['99999999999999999999', null],
    'a scaled figure beyond an int' => ['9999999999999G', null],
    'the largest scaled figure that does fit' => ['8589934591G', 8589934591 * 1073741824],
]);

it('raises only towards a higher ceiling', function (?string $configured, string $current, ?string $target): void {
    expect(MemoryLimit::target($configured, $current))->toBe($target);
})->with([
    'a higher limit applies' => ['2G', '512M', '2G'],
    'an equal limit is a no-op' => ['512M', '512M', null],
    'a lower limit is refused' => ['256M', '512M', null],
    'an equal limit in other units is a no-op' => ['1024M', '1G', null],
    // The knob exists to prevent an OOM, so it never introduces one — and never removes the ceiling either.
    'an unlimited process is never capped' => ['2G', '-1', null],
    'unlimited cannot be asked for' => ['-1', '512M', null],
    'an overlong figure leaves the process alone' => ['9999999999999G', '512M', null],
    'nothing configured leaves the process alone' => [null, '512M', null],
    'a blank value leaves the process alone' => ['   ', '512M', null],
    'an unparseable value leaves the process alone' => ['heaps', '512M', null],
    // A current value we can't read shouldn't block a legitimate raise.
    'an unreadable current limit still raises' => ['2G', 'unknown', '2G'],
]);

it('recognises memory exhaustion and only memory exhaustion', function (?array $error, bool $expected): void {
    expect(OutOfMemoryNotice::isExhaustion($error))->toBe($expected);
})->with([
    'exhaustion' => [['type' => E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted', 'file' => 'x', 'line' => 1], true],
    'another fatal' => [['type' => E_ERROR, 'message' => 'Call to undefined method Foo::bar()', 'file' => 'x', 'line' => 1], false],
    'a warning mentioning memory' => [['type' => E_WARNING, 'message' => 'Allowed memory size', 'file' => 'x', 'line' => 1], false],
    'a clean shutdown' => [null, false],
]);

it('names both levers, and the current ceiling, in the out-of-memory notice', function (): void {
    $text = OutOfMemoryNotice::text('128M');

    expect($text)->toContain('128M')
        ->and($text)->toContain('docuccino.engine.memory_limit')
        ->and($text)->toContain('--memory-limit=2G')
        ->and($text)->toContain('docuccino.engine.project_paths');
});

it('declares the flag on every command that builds a document', function (string $command): void {
    expect(Artisan::all()[$command]->getDefinition()->hasOption('memory-limit'))->toBeTrue();
})->with(['docuccino:export', 'docuccino:cache', 'docuccino:validate', 'docuccino:diff']);

it('does not offer the flag on the command that builds nothing', function (): void {
    expect(Artisan::all()['docuccino:clear']->getDefinition()->hasOption('memory-limit'))->toBeFalse();
});

it('captures the flag into the engine config the factory reads', function (): void {
    config(['docuccino.engine.memory_limit' => null]);

    MemoryLimitOption::capture(new CommandStarting(
        'docuccino:export',
        new ArrayInput(['--memory-limit' => '3G']),
        new NullOutput,
    ));

    expect(config('docuccino.engine.memory_limit'))->toBe('3G');
});

it('ignores the flag for commands that are not ours', function (): void {
    config(['docuccino.engine.memory_limit' => null]);

    MemoryLimitOption::capture(new CommandStarting(
        'migrate',
        new ArrayInput(['--memory-limit' => '3G']),
        new NullOutput,
    ));

    expect(config('docuccino.engine.memory_limit'))->toBeNull();
});

// --- Who may move the process ceiling ---------------------------------------

it('refuses to tune the process until one of our commands has started', function (): void {
    // A web request resolving the engine (the viewer's `generate` source does, on every `.json` hit) must
    // not touch the ceiling the process serves every other request under. PHP_SAPI can't decide this —
    // Octane serves HTTP as `cli` — so the marker is what the factory reads.
    expect(ConsoleBuild::active())->toBeFalse()
        ->and(app(TypeEngineFactory::class)->mayTuneProcess())->toBeFalse();

    MemoryLimitOption::capture(new CommandStarting('docuccino:export', new ArrayInput([]), new NullOutput));

    expect(ConsoleBuild::active())->toBeTrue()
        ->and(app(TypeEngineFactory::class)->mayTuneProcess())->toBeTrue();
});

it('is not marked a console build by somebody else\'s command', function (): void {
    MemoryLimitOption::capture(new CommandStarting('migrate', new ArrayInput([]), new NullOutput));

    expect(ConsoleBuild::active())->toBeFalse()
        ->and(app(TypeEngineFactory::class)->mayTuneProcess())->toBeFalse();
});

it('leaves the process ceiling alone when it may not tune it', function (): void {
    // The gate is upstream of every ini_set: a factory that may not tune reports so, and the viewer's
    // request path never reaches applyMemoryLimit().
    $before = ini_get('memory_limit');

    $factory = new TypeEngineFactory(
        basePath: base_path(),
        tmpDir: storage_path('docuccino'),
        engine: new EnginePackage(static fn (string $class): bool => false),
    );
    $factory->make(['mode' => 'in-process', 'memory_limit' => '9999M']);

    expect($factory->mayTuneProcess())->toBeFalse()
        ->and(ini_get('memory_limit'))->toBe($before);
});
