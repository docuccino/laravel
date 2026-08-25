<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Spatie\LaravelData\Mappers\NameMapper;
use Spatie\LaravelData\Mappers\ProvidedNameMapper;

/*
 * The guard behind the reflector's built-in name-mapper table. A dataset only proves the rows it lists,
 * and this table shipped without `KebabCaseMapper` for two spatie releases: a Data class using it had
 * every property documented under the WRONG key, which is worse than vague — a generated client sends
 * `firstName` where the API accepts `first-name`. So the source of truth is read here instead: the
 * mappers the installed spatie/laravel-data actually ships, and the names its own mappers actually
 * produce. A mapper added upstream fails this until the table learns it.
 */

/**
 * Every concrete name mapper the installed package ships, by FQCN. Found from the interface's own file
 * so the scan follows the install rather than a guessed vendor path.
 *
 * @return list<class-string<NameMapper>>
 */
function shippedNameMappers(): array
{
    $directory = dirname((string) (new ReflectionClass(NameMapper::class))->getFileName());

    $mappers = [];
    foreach (glob($directory.'/*.php') ?: [] as $file) {
        $fqcn = 'Spatie\\LaravelData\\Mappers\\'.basename($file, '.php');
        if (! class_exists($fqcn) || ! is_a($fqcn, NameMapper::class, true)) {
            continue;
        }

        $mappers[] = $fqcn;
    }

    sort($mappers);

    return $mappers;
}

/**
 * The mappers a `#[MapName(X::class)]` can name bare — spatie resolves those out of the container, so a
 * required constructor argument it cannot fill puts a mapper outside the set the table is answerable for.
 *
 * @return list<class-string<NameMapper>>
 */
function bareNameMappers(): array
{
    return array_values(array_filter(
        shippedNameMappers(),
        static fn (string $fqcn): bool => ((new ReflectionClass($fqcn))->getConstructor()?->getNumberOfRequiredParameters() ?? 0) === 0,
    ));
}

it('scans a plausible number of shipped mappers', function (): void {
    // A scan that matched nothing would pass every assertion below it while proving nothing. Five is
    // what the oldest spatie/laravel-data this package resolves ships (4.13, the --prefer-lowest floor:
    // snake, camel, studly, lower, upper); 4.18 added kebab.
    expect(count(bareNameMappers()))->toBeGreaterThanOrEqual(5)
        ->and(shippedNameMappers())->toContain(ProvidedNameMapper::class);
});

it('recognises every mapper the installed package ships, and maps the name it maps', function (): void {
    // Compared against the vendor's own map(), not against a name guessed from the class — `KebabCase`
    // could as easily have meant `Str::snake('-')`, and a table written from the class name would agree
    // with itself while documenting the wrong key.
    $unrecognised = [];
    $disagreed = [];

    foreach (bareNameMappers() as $fqcn) {
        $vendor = (string) (new $fqcn)->map('displayName');
        $ours = DataClassReflector::mapWithMapper($fqcn, 'displayName');

        if ($ours === null) {
            $unrecognised[] = $fqcn;
        } elseif ($ours !== $vendor) {
            $disagreed[] = $fqcn.': published '.$ours.', spatie maps '.$vendor;
        }
    }

    expect($unrecognised)->toBe([])
        ->and($disagreed)->toBe([]);
});

it('leaves out only the mappers a MapName attribute cannot name bare', function (): void {
    // The one exclusion, stated rather than derived, so a second constructor-taking mapper upstream
    // stops here for a decision instead of being swallowed by the filter above. ProvidedNameMapper
    // returns a fixed name — it is how spatie models a literal `#[MapName('key')]`, not a transform.
    expect(array_values(array_diff(shippedNameMappers(), bareNameMappers())))
        ->toBe([ProvidedNameMapper::class]);
});
