<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderConfig;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderConfigDigestContributor;
use Docuccino\Laravel\Registry\DefaultExtensions;
use Docuccino\Laravel\Registry\IntegrationToggles;

/*
 * The environment-digest contributor SET, read off the source rather than off a list somebody kept.
 *
 * A contributor exists because some value reached no cache-key input, so "written and never wired" is
 * the failure this chain is most exposed to: the class compiles, its unit test passes, and no fragment
 * is ever keyed on the fact it reads. Two rows checked one contributor each by name, which says nothing
 * about the ninth one added later.
 *
 * So every implementation under the adapter's `src/` owes a row below saying how it is gated, and the
 * table is compared against the scan in both directions — a table short by an entry is a failure here
 * rather than a silence.
 */

/**
 * How each contributor reaches a document: an `integrations.*` key when its integration gates it, or
 * null when it is registered unconditionally.
 *
 * @return array<class-string<EnvironmentDigestContributor>, ?string>
 */
function environmentDigestGating(): array
{
    return [
        // The framework's own vocabulary, or Docuccino's own config: no package owns these, so an
        // application that installed none of the integrations still owes its fragments the key.
        'Docuccino\Laravel\Integrations\Support\AuthConfigDigestContributor' => null,
        'Docuccino\Laravel\Support\GatePoliciesDigestContributor' => null,
        'Docuccino\Laravel\Support\LeakageDigestContributor' => null,
        // Registered render callbacks are read off the booted exception handler, which every
        // application has — so the inferred-handler chain is not toggled either.
        'Docuccino\Laravel\Integrations\InferredHandler\RenderCallbackDigestContributor' => null,
        // A package's globals, which a document that disabled the integration must never be keyed on.
        'Docuccino\Laravel\Integrations\Eloquent\MorphMapDigestContributor' => 'eloquent',
        'Docuccino\Laravel\Integrations\JsonApiPaginate\JsonApiPaginateConfigDigestContributor' => 'json_api_paginate',
        'Docuccino\Laravel\Integrations\Passport\PassportDigestContributor' => 'passport',
        'Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderConfigDigestContributor' => 'query_builder',
        'Docuccino\Laravel\Integrations\RateLimit\RateLimiterDigestContributor' => 'rate_limit',
        'Docuccino\Laravel\Integrations\Sanctum\SanctumDigestContributor' => 'sanctum',
        'Docuccino\Laravel\Integrations\SpatieData\SpatieDataDigestContributor' => 'spatie_data',
    ];
}

/**
 * Every `EnvironmentDigestContributor` the adapter declares, read off `src/`.
 *
 * The FQCN comes from the PSR-4 path and the answer from the autoloader, so nothing here parses PHP:
 * a class the interface no longer names drops out on its own, and a new file is picked up by being on
 * disk.
 *
 * @return list<class-string<EnvironmentDigestContributor>>
 */
function environmentDigestDeclared(): array
{
    $classes = [];
    foreach (array_keys(environmentDigestSources()) as $relative) {
        $fqcn = 'Docuccino\\Laravel\\'.str_replace('/', '\\', substr($relative, 0, -4));
        if (is_a($fqcn, EnvironmentDigestContributor::class, true)) {
            $classes[] = $fqcn;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * The adapter's PHP sources, as `relative/path.php` => contents.
 *
 * @return array<string, string>
 */
function environmentDigestSources(): array
{
    $root = dirname(__DIR__, 2).'/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    $sources = [];
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $sources[str_replace($root.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
    }

    return $sources;
}

/**
 * The contributor classes a document resolves to, whether the built-in set named them or handed over a
 * constructed instance.
 *
 * @param  array<string, mixed>  $raw
 * @return list<class-string>
 */
function environmentDigestContributorsFor(array $raw): array
{
    $document = app(DocumentConfigFactory::class)->make('default', $raw, 'skeleton');

    $classes = [];
    foreach (DefaultExtensions::all($document) as $extension) {
        $class = is_object($extension) ? $extension::class : $extension;
        if (is_a($class, EnvironmentDigestContributor::class, true)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/** The document settings with every toggleable integration turned off. */
function environmentDigestNothingEnabled(): array
{
    /** @var array<string, mixed> $raw */
    $raw = documentSettings();
    foreach (array_keys(IntegrationToggles::descriptors()) as $key) {
        $raw['integrations'][$key]['enabled'] = false;
    }

    return $raw;
}

it('names every environment-digest contributor the adapter declares, and none it does not', function (): void {
    $declared = environmentDigestDeclared();
    $tabled = array_keys(environmentDigestGating());
    sort($tabled);

    // The plausible minimum beside the real assertion: a scan whose pattern stopped matching would
    // otherwise agree with an empty table forever.
    expect($declared)->toHaveCount(11)
        ->and($tabled)->toBe($declared);
});

it('registers an ungated contributor for a document with every integration turned off', function (string $contributor): void {
    // The claim each of these makes in its own docblock, executed: this is the product's or the
    // framework's own state, so an application that installed no integration still owes it the key.
    expect(environmentDigestContributorsFor(environmentDigestNothingEnabled()))->toContain($contributor);
})->with(array_keys(array_filter(environmentDigestGating(), static fn (?string $key): bool => $key === null)));

it('gates a package contributor with the integration that owns it, in both directions', function (string $contributor, string $key): void {
    $toggle = IntegrationToggles::descriptors()[$key] ?? null;

    // Asserted rather than skipped: with the package absent the presence half below would pass on an
    // empty reading, and this row would go quiet for whichever integration stopped being installed.
    expect($toggle)->not->toBeNull()
        ->and($toggle?->installed())->toBeTrue();

    /** @var array<string, mixed> $enabled */
    $enabled = documentSettings();
    $enabled['integrations'][$key]['enabled'] = true;

    $disabled = environmentDigestNothingEnabled();

    expect(environmentDigestContributorsFor($enabled))->toContain($contributor)
        ->and(environmentDigestContributorsFor($disabled))->not->toContain($contributor);
})->with(array_map(
    static fn (string $contributor, string $key): array => [$contributor, $key],
    array_keys(array_filter(environmentDigestGating(), static fn (?string $key): bool => $key !== null)),
    array_values(array_filter(environmentDigestGating(), static fn (?string $key): bool => $key !== null)),
));

/*
 * The separator every segment is joined on. Stated on the contract
 * ({@see EnvironmentDigestContributor}) and checked here, because the two collisions it prevents were
 * both live: a comma read `allow: ['/a,/b']` as the two-entry list it safelists nothing like, and the
 * Query Builder bag — whose `delimiter` defaults to a comma — digested two configs that publish
 * different list contracts to one value.
 */

it('joins every environment-digest segment on a byte no value it reads can hold', function (): void {
    $separators = [];
    $glue = [];

    foreach (environmentDigestDeclared() as $contributor) {
        $source = (string) file_get_contents((string) (new ReflectionClass($contributor))->getFileName());
        $separators = [...$separators, ...environmentDigestImplodeSeparators($source)];
        $glue = [...$glue, ...environmentDigestConcatenationGlue($source)];
    }

    // The minimum again: one join per contributor at the least, so a tokeniser that stopped seeing
    // them cannot report a clean scan.
    expect(count($separators))->toBeGreaterThanOrEqual(11)
        ->and(array_values(array_unique($separators)))->toBe(['"\0"'])
        // A literal between two `.` operators is a separator by another name, and the one the Passport
        // and morph-map segments used to pair a key with its value.
        ->and($glue)->toBe([]);
});

it('refuses a separator a joined value could hold', function (string $source, string $offence): void {
    // The guard above, executed against the code it exists to refuse rather than trusted to be right.
    expect([...environmentDigestImplodeSeparators($source), ...environmentDigestConcatenationGlue($source)])
        ->toBe([$offence]);
})->with([
    'a comma-joined list' => ['<?php return implode(\',\', $parts);', "','"],
    'a pipe-joined list' => ['<?php return implode("|", $parts);', '"|"'],
    'a pair glued with an arrow' => ['<?php $records[] = $token.\'=>\'.$label;', "'=>'"],
]);

/**
 * The source text of every `implode()` separator in one file.
 *
 * Tokenised rather than grepped, for the reason the repo's other source scans tokenise: a separator
 * named inside a comment or another string is not a separator, and the tokeniser draws that line free.
 *
 * @return list<string>
 */
function environmentDigestImplodeSeparators(string $source): array
{
    $tokens = environmentDigestSignificantTokens($source);

    $separators = [];
    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'implode') {
            continue;
        }

        $argument = $tokens[$index + 2] ?? null;
        if (($tokens[$index + 1] ?? null) === '(' && is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING) {
            $separators[] = $argument[1];
        }
    }

    return $separators;
}

/**
 * The source text of every string literal sitting between two concatenation operators — `$a.'=>'.$b`.
 *
 * @return list<string>
 */
function environmentDigestConcatenationGlue(string $source): array
{
    $tokens = environmentDigestSignificantTokens($source);

    $glue = [];
    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        if (($tokens[$index - 1] ?? null) === '.' && ($tokens[$index + 1] ?? null) === '.') {
            $glue[] = $token[1];
        }
    }

    return $glue;
}

/**
 * One source's tokens with whitespace and comments dropped, re-indexed so neighbours really are
 * neighbours.
 *
 * @return list<array{0: int, 1: string, 2: int}|string>
 */
function environmentDigestSignificantTokens(string $source): array
{
    $tokens = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $tokens[] = $token;
    }

    return $tokens;
}

it('keys two Query Builder bags apart when a comma in one value would have collapsed them', function (): void {
    // The instance that made the separator a rule rather than a preference. `delimiter` decides whether
    // list values carry the comma-array contract at all, and its own default IS a comma — so these two
    // bags document differently and used to key one warm fragment.
    $splitting = new QueryBuilderConfig(existsSuffix: 'Exists', delimiter: ',');
    $whole = new QueryBuilderConfig(existsSuffix: 'Exists,', delimiter: '');

    expect($splitting->splitsOnComma())->not->toBe($whole->splitsOnComma())
        ->and((new QueryBuilderConfigDigestContributor($splitting))->digest())
        ->not->toBe((new QueryBuilderConfigDigestContributor($whole))->digest());
});
