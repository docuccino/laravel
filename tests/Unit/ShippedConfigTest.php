<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\ConfigSplit;
use Illuminate\Foundation\Console\ConfigCacheCommand;

require_once dirname(__DIR__, 4).'/tools/config-reference-sync.php';

/**
 * Whether one dotted path lies on a branch of {@see ConfigSplit::FRAMEWORK_KEYS}: the key itself, a
 * bag that holds one, or an option below one. A `*` segment in the split matches any one segment.
 *
 * The three cases are one comparison, over the segments the two paths share — `documents` and
 * `documents.*` are on the `documents.*.viewer` branch as its bags, and `documents.*.viewer.route` is
 * on it as one of its options. Anything that diverges before it runs out is a key the framework
 * config has no business declaring.
 */
function shippedConfigOnFrameworkBranch(string $path): bool
{
    $segments = explode('.', $path);

    foreach (ConfigSplit::FRAMEWORK_KEYS as $key) {
        $branch = explode('.', $key);
        $shared = min(count($branch), count($segments));
        $matches = true;

        for ($index = 0; $index < $shared; $index++) {
            $matches = $matches && ($branch[$index] === '*' || $branch[$index] === $segments[$index]);
        }

        if ($matches) {
            return true;
        }
    }

    return false;
}

/**
 * The published config file has to be pure data. Laravel loads every file in `config/` at boot, so
 * one class reference here fatals an app that installed Docuccino as a dev dependency and then boots
 * production with `--no-dev` — the packages are pruned, the class is gone.
 */
it('references no class and calls nothing but env', function (): void {
    // Token-scanning rather than a runtime include: the tokenizer keeps comments and strings in their
    // own token kinds, so the commented-out `App\Docs\…::class` examples are ignored while an unused
    // import — which a plain `require` would never even resolve — is still caught.
    $tokens = PhpToken::tokenize((string) file_get_contents(dirname(__DIR__, 2).'/config/docuccino.php'));

    $classReferences = [];
    $calls = [];

    foreach ($tokens as $index => $token) {
        if ($token->is([T_USE, T_NEW, T_DOUBLE_COLON, T_ATTRIBUTE, T_INSTANCEOF, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
            $classReferences[] = $token->text;

            continue;
        }

        // A bare T_STRING is `true`/`false`/`null` or an array key; one followed by `(` is a call.
        if (! $token->is(T_STRING)) {
            continue;
        }

        $next = $index + 1;
        while (isset($tokens[$next]) && $tokens[$next]->isIgnorable()) {
            $next++;
        }

        if (isset($tokens[$next]) && $tokens[$next]->text === '(') {
            $calls[] = $token->text;
        }
    }

    expect($classReferences)->toBe([])
        ->and(array_values(array_unique($calls)))->toBe(['env']);
});

/*
 * And it has to be exactly the split, in both directions.
 *
 * A build key left here is a setting an author edits and no build reads — reported, never merged. A
 * framework key MISSING here is worse and silent: `enabled` gone means the master switch reads its
 * default, and a `viewer` gone means routes that were registered simply are not, with no diagnostic
 * anywhere, because boot has nothing to compare against.
 *
 * Held against `ConfigSplit::FRAMEWORK_KEYS` rather than a list retyped here, because two lists is
 * how the declaration and the file part company. `ConfigReferenceSyncTest` states the same answer
 * literally, which is the half of the pair that catches the declaration itself being widened.
 */
it('declares exactly the keys ConfigSplit says the framework owns', function (): void {
    $declared = config_reference_checkable(config_reference_declared_keys(
        (string) file_get_contents(dirname(__DIR__, 2).'/config/docuccino.php'),
    ));

    $foreign = array_values(array_filter(
        $declared,
        static fn (string $path): bool => ! shippedConfigOnFrameworkBranch($path),
    ));

    $absent = array_values(array_filter(
        ConfigSplit::FRAMEWORK_KEYS,
        static fn (string $key): bool => ! in_array($key, $declared, true),
    ));

    expect($declared)->not->toBeEmpty()
        ->and($foreign)->toBe([], 'keys in config/docuccino.php that no longer belong there')
        ->and($absent)->toBe([], 'keys ConfigSplit says belong there and the file does not declare');
});

it('refuses a build key left in that file, and a framework key missing from it', function (): void {
    // The guard above passes on the shipped file, which says nothing about what it would REFUSE. So
    // the two failures are written out and the predicate is asked directly, rather than the shipped
    // file being edited to find out.
    expect(shippedConfigOnFrameworkBranch('lint'))->toBeFalse()
        ->and(shippedConfigOnFrameworkBranch('lint.leakage.enabled'))->toBeFalse()
        ->and(shippedConfigOnFrameworkBranch('cache.enabled'))->toBeFalse()
        ->and(shippedConfigOnFrameworkBranch('documents.*.info'))->toBeFalse()
        // And the shapes it has to accept: each key, the bags above one, the options below one.
        ->and(shippedConfigOnFrameworkBranch('enabled'))->toBeTrue()
        ->and(shippedConfigOnFrameworkBranch('cache'))->toBeTrue()
        ->and(shippedConfigOnFrameworkBranch('cache.store'))->toBeTrue()
        ->and(shippedConfigOnFrameworkBranch('documents'))->toBeTrue()
        ->and(shippedConfigOnFrameworkBranch('documents.*.viewer'))->toBeTrue()
        ->and(shippedConfigOnFrameworkBranch('documents.*.viewer.route'))->toBeTrue();

    // The missing direction, run against a file that drops one: nothing about the answer may depend
    // on the shipped file being the one asked.
    $declared = config_reference_checkable(config_reference_declared_keys(
        "<?php\n\nreturn ['documents' => ['default' => ['viewer' => ['route' => '/docs/api']]]];",
    ));

    expect(array_values(array_filter(
        ConfigSplit::FRAMEWORK_KEYS,
        static fn (string $key): bool => ! in_array($key, $declared, true),
    )))->toBe(['enabled', 'cache.store']);
});

/*
 * And it has to survive `config:cache`, which the production guide states as a property of the file.
 * The command serializes the WHOLE config array with `var_export()` and requires it back
 * ({@see ConfigCacheCommand::handle}), so one closure anywhere under `docuccino` fails the command for
 * the entire application and not just for this package — which is why the viewer's `gate` names an
 * ability rather than taking a predicate. Round-tripping the real file is total where a token scan for
 * `fn`/`function` would only be a guess at the shapes.
 */
it('round-trips through the serialization config:cache uses', function (): void {
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__, 2).'/config/docuccino.php';

    /** @var array<string, mixed> $cached */
    $cached = eval('return '.var_export($config, true).';');

    expect($cached)->toBe($config)
        // And what a closure under `docuccino` would do to the command, which is the reason the file
        // holds none: `var_export` writes one as `\Closure::__set_state(...)`, and requiring that back
        // fatals. The round-trip above is the assertion; this is what it is guarding against.
        ->and(static fn (): mixed => eval('return '.var_export(['gate' => static fn (): bool => true], true).';'))
        ->toThrow(Error::class, 'Call to undefined method Closure::__set_state()');
});

it('reads config:cache as still serializing the config with var_export', function (): void {
    // The premise of the test above. The command itself cannot run in this harness — it re-bootstraps
    // a fresh application from the testbench skeleton, which never registers this package, so the
    // config under test is not in the array it caches — so the step it fails at is exercised directly
    // and the step is pinned here. If the framework ever serializes config some other way, this fails
    // and the claim gets re-checked rather than repeated.
    $file = (new ReflectionClass(ConfigCacheCommand::class))->getFileName();

    expect((string) file_get_contents((string) $file))->toContain('var_export($config, true)');
});
