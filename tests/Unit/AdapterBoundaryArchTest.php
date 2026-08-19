<?php

declare(strict_types=1);

/**
 * `docuccino/laravel` and `docuccino/inference-phpstan` are SIBLINGS, not a chain: the adapter requires
 * core and Illuminate, and treats the engine as an optional dev-only install (probed by string through
 * `Engine\EnginePackage`). These rules keep it that way — a single `use Docuccino\Inference\…` or
 * `use PHPStan\…` would fatal a production app that only requires the adapter, and would drag a static
 * analyser back into its vendor dir.
 */
arch('the adapter never imports the inference engine')
    ->expect('Docuccino\Laravel')
    ->not->toUse('Docuccino\Inference\PhpStan');

it('imports no analysis toolchain at all', function (): void {
    expect(importsMatching('laravel', '/^(PHPStan|Larastan)\\\\/'))->toBe([]);
});

/**
 * The scan above is the only reader of that rule, so it owes the same grammar PHP has: a line-oriented
 * `^use …` regex sees imports and nothing else, and an analyser named in an expression, an attribute, a
 * type position or a group import would fatal exactly the same production app while reading green. The
 * other half is that a name in a STRING is NOT a reference — it is the sanctioned probe for an optional
 * package (`EnginePackage::BUILDER`) — and neither is one in a comment.
 */
it('sees an analyser named anywhere in code, and only in code', function (): void {
    $directory = sys_get_temp_dir().'/docuccino-reference-scan-'.bin2hex(random_bytes(8));
    mkdir($directory.'/Nested', 0755, true);
    file_put_contents($directory.'/Nested/Sneaky.php', <<<'PHP'
        <?php

        namespace Docuccino\Laravel\Nested;

        use Illuminate\Routing\Route;
        use Larastan\Grouped\{Alpha, Beta\Gamma as Renamed};

        final class Sneaky
        {
            public const string PROBE = 'PHPStan\Analyser\Scope';

            public function __construct(private readonly \PHPStan\Reflection\ReflectionProvider $provider) {}

            #[\Larastan\Marker]
            public function run(Route $route): string
            {
                // \PHPStan\Node\Commented is not a reference.
                return \PHPStan\Type\ObjectType::class;
            }
        }
        PHP);

    try {
        expect(referencesIn($directory, '/^(PHPStan|Larastan)\\\\/'))->toBe([
            'Nested/Sneaky.php: Larastan\Grouped\Alpha',
            'Nested/Sneaky.php: Larastan\Grouped\Beta\Gamma',
            'Nested/Sneaky.php: Larastan\Marker',
            'Nested/Sneaky.php: PHPStan\Reflection\ReflectionProvider',
            'Nested/Sneaky.php: PHPStan\Type\ObjectType',
        ]);

        // The file's own namespace is not something it imports, and a plain import still counts.
        expect(referencesIn($directory, '/^(Docuccino|Illuminate)\\\\/'))
            ->toBe(['Nested/Sneaky.php: Illuminate\Routing\Route']);
    } finally {
        unlink($directory.'/Nested/Sneaky.php');
        rmdir($directory.'/Nested');
        rmdir($directory);
    }
});

/**
 * `docuccino/laravel` ships to PRODUCTION — it registers the runtime viewer endpoint — while
 * `Docuccino\Laravel\Testing` is the contract-testing surface, which names `Illuminate\Testing` and
 * PHPUnit. Both are dev-only installs in a real application, so nothing on the shipping path may
 * reach that namespace: a provider that touched it would fatal a `--no-dev` boot.
 *
 * The guard is one-directional on purpose. The testing surface freely uses the adapter (it builds
 * documents through `DocumentBuilder` exactly as the commands do); the adapter must never use it.
 */
it('never reaches the test-only surface from code that ships to production', function (): void {
    $shipping = array_values(array_filter(
        referencesIn(dirname(__DIR__, 2).'/src', '/^(Docuccino\\\\Laravel\\\\Testing|Illuminate\\\\Testing|PHPUnit)\\\\/'),
        static fn (string $reference): bool => ! str_starts_with($reference, 'Testing/'),
    ));

    expect($shipping)->toBe([]);
});

/** The other half: the guard above is only worth anything if the scan can still see such a reference. */
it('would see a test-only reference if one appeared', function (): void {
    $found = referencesIn(dirname(__DIR__, 2).'/src', '/^(Docuccino\\\\Laravel\\\\Testing|Illuminate\\\\Testing|PHPUnit)\\\\/');

    expect($found)->not->toBe([])
        ->and($found)->each->toStartWith('Testing/');
});
