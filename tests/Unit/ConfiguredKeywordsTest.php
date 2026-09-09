<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Laravel\Config\ConfiguredKeywords;
use Docuccino\Laravel\Config\DeclaredSettings;
use Docuccino\Laravel\Config\ViewerConfig;

/*
 * The guards over the closed-set keyword family. Three of them, covering the three directions a set
 * can go wrong in, because each on its own is silent about the others:
 *
 *  - the FILE says a set the code does not (a documented value the build refuses),
 *  - the CODE knows a setting the file declares and nothing reads (a keyword added with no reading),
 *  - the CODE branches on a value the set leaves out (a working value reported as unknown, which is
 *    the worse half of the same defect — it marks a correct configuration wrong).
 *
 * The shipped configuration is the authority all three are held to. It is already the declaration of
 * the settings surface ({@see DeclaredSettings}), it shows every setting with its default, and every
 * closed-set setting in it spells its values out beside itself — so the accepted sets are read off
 * those bytes rather than trusted to a constant that agrees with them by hand.
 */

/**
 * Every setting the shipped configuration declares a closed set for, as dotted path => the values it
 * lists, in order. Both files: `docuccino.yaml` and the framework config, whose `viewer` bag is read
 * on a request and so is configured there.
 *
 * The set is read off the comment beside the setting — `versioning: 'none' # none | semver | date` —
 * which is the file's own convention for a keyword and the only machine-readable statement of a set
 * outside the code being checked. A member is the leading token of its segment, so a segment that goes
 * on to explain itself (`type-array: type: [string, null]`) still names one value.
 *
 * Its limit, stated because a guard's blind spot belongs beside it: a keyword setting added with NO
 * alternation comment is invisible here. That is what the shipped file's own standard forbids — every
 * setting shown with its values — and what the configuration reference, held to this file key for key,
 * would leave undocumented.
 *
 * @return array<string, list<string>>
 */
function shippedKeywordSets(): array
{
    $sets = [];

    foreach (['docuccino.yaml' => '#', 'docuccino.php' => '//'] as $file => $marker) {
        $path = dirname(__DIR__, 2).'/config/'.$file;

        foreach (declaredSetsIn((string) file_get_contents($path), $marker) as $setting => $values) {
            $sets[$setting] = $values;
        }
    }

    return $sets;
}

/**
 * One shipped file's alternating comments, as dotted path => values. Indentation gives the path: both
 * files nest a section by indenting under it, so the enclosing keys are whatever is still open at a
 * shallower indent. A commented-out option is uncommented in place first, exactly as
 * {@see DeclaredSettings} uncomments one, because a commented option is still an option.
 *
 * @return array<string, list<string>>
 */
function declaredSetsIn(string $source, string $marker): array
{
    $sets = [];
    /** @var array<int, string> $open  indent => the key open at it */
    $open = [];

    foreach (explode("\n", $source) as $line) {
        $line = preg_replace('/^(\s*)'.preg_quote($marker, '/').' ?/', '$1', $line) ?? $line;

        if (preg_match('/^(\s*)\'?([A-Za-z_][A-Za-z0-9_.-]*)\'?\s*(?::|=>)(.*)$/', $line, $match) !== 1) {
            continue;
        }

        [, $indent, $key, $rest] = $match;
        $depth = strlen($indent);

        foreach (array_keys($open) as $at) {
            if ($at >= $depth) {
                unset($open[$at]);
            }
        }
        $open[$depth] = $key;
        ksort($open);

        $comment = strpos($rest, $marker);
        if ($comment === false) {
            continue;
        }

        $values = keywordAlternation(substr($rest, $comment + strlen($marker)));
        if ($values !== null) {
            // `documents.default.…` is `documents.*.…`: the segment under `documents` is a name the
            // application chooses, and the catalogue is written in the shape an author reads.
            $sets[implode('.', DeclaredSettings::wildcarded(array_values($open)))] = $values;
        }
    }

    return $sets;
}

/**
 * A comment's `a | b | c` alternation as its values, or null when the comment is prose. Two members
 * minimum: one "alternative" is not a set, and a sentence that happens to carry a pipe is not either.
 *
 * @return list<string>|null
 */
function keywordAlternation(string $comment): ?array
{
    $segments = explode('|', $comment);
    if (count($segments) < 2) {
        return null;
    }

    $values = [];
    foreach ($segments as $segment) {
        if (preg_match('/^\s*([A-Za-z0-9_-]+)[\s:]/', $segment.' ', $token) !== 1) {
            return null;
        }

        $values[] = $token[1];
    }

    return $values;
}

it('reads the set the shipped configuration states for every keyword setting it catalogues', function (): void {
    $shipped = shippedKeywordSets();
    $catalogue = ConfiguredKeywords::catalogue();

    $wrong = [];
    foreach ($catalogue as $path => [$accepted, $default]) {
        if (($shipped[$path] ?? null) !== $accepted) {
            $wrong[] = sprintf(
                '%s: code takes [%s], the shipped configuration states [%s]',
                $path,
                implode(', ', $accepted),
                implode(', ', $shipped[$path] ?? ['nothing']),
            );
        }
    }

    expect($wrong)->toBe([], 'settings whose accepted set disagrees with the file that declares it')
        // Anti-vacuity: a comment scan that stopped matching would agree with an empty catalogue.
        ->and(count($catalogue))->toBeGreaterThanOrEqual(9)
        ->and(count($shipped))->toBeGreaterThanOrEqual(9);
});

it('answers the default the shipped configuration shows beside every keyword setting', function (): void {
    // Read off the shipped bytes rather than off the constants: the file is what an install writes, so
    // a diagnostic naming a fallback the file does not show is a diagnostic that is checkable and wrong.
    $tree = DeclaredSettings::shippedTree();
    $framework = require dirname(__DIR__, 2).'/config/docuccino.php';

    $wrong = [];
    foreach (ConfiguredKeywords::catalogue() as $path => [$accepted, $default]) {
        $written = shippedValueAt(str_starts_with($path, 'documents.*.viewer.') ? $framework : $tree, $path);

        if ($written !== $default) {
            $wrong[] = sprintf('%s: code answers %s, the shipped configuration shows %s', $path, $default, var_export($written, true));
        }
    }

    expect($wrong)->toBe([], 'settings whose default disagrees with the file that shows it');
});

/**
 * One path's value out of a parsed settings tree, with `*` matching whichever single key is there —
 * which is `default` in both shipped files.
 *
 * @param  array<array-key, mixed>  $tree
 */
function shippedValueAt(array $tree, string $path): mixed
{
    $node = $tree;

    foreach (explode('.', $path) as $segment) {
        if (! is_array($node)) {
            return null;
        }

        if ($segment === '*') {
            $node = array_values($node)[0] ?? null;

            continue;
        }

        $node = $node[$segment] ?? null;
    }

    return $node;
}

/**
 * The full-set half: the shipped configuration declares a set for exactly the settings something reads
 * as one, so a keyword setting added later with no reading fails here rather than coercing in silence.
 *
 * A member owing no answer carries a row, and there is one: `engine.mode`.
 *
 * @return array<string, string>
 */
function keywordSettingsReportedElsewhere(): array
{
    return [
        'engine.mode' => 'Every setting in the catalogue falls back to the default the shipped file shows'
            .' beside it, so one reader that knows the key, the set and that default can produce its'
            .' refusal. engine.mode falls back to whatever will still ANALYSE rather than to the'
            .' documented default, it is reported only where an engine is installed to analyse with, and'
            .' DOCUCCINO_ENGINE can supply the value — so a message sending its author to a key in'
            .' docuccino.yaml would name a file the value need not be in. It keeps engine.mode-unknown,'
            .' whose help names the variable instead.',
    ];
}

it('catalogues every keyword setting the shipped configuration declares a set for', function (): void {
    $shipped = shippedKeywordSets();
    $catalogue = ConfiguredKeywords::catalogue();
    $excused = keywordSettingsReportedElsewhere();

    $uncovered = [];
    foreach (array_keys($shipped) as $path) {
        if (! isset($catalogue[$path]) && ! isset($excused[$path])) {
            $uncovered[] = $path;
        }
    }

    $stale = [];
    foreach ($excused as $path => $reason) {
        if (! isset($shipped[$path])) {
            $stale[] = $path.': excused, but the shipped configuration declares no set for it';
        }
        if (isset($catalogue[$path])) {
            $stale[] = $path.': excused, but the catalogue reads it after all';
        }
        if (trim($reason) === '') {
            $stale[] = $path.': excused without saying why';
        }
    }

    expect($uncovered)->toBe([], 'settings the shipped configuration states a set for that nothing reads as one')
        ->and($stale)->toBe([]);
});

/**
 * The soundness half, and the one that costs a working configuration if it is wrong: every value the
 * product BRANCHES on has to be inside the set, or a build reports a value it goes on to honour — an
 * accepted set narrower than what the product answers to marks a correct configuration wrong.
 *
 * Each row names the setting, the files that act on its keyword, and the EXPRESSIONS the keyword is
 * held in at those sites. The literals are read out of the comparisons and `match` arms over those
 * expressions, so a branch added for a new keyword fails here until the set names it — this is the
 * guard that fails when the list is short, rather than the one that agrees with whatever the code does.
 *
 * @return array<string, array{0: non-empty-list<string>, 1: list<string>, 2: list<string>}>
 */
function keywordBranchSites(): array
{
    return [
        'representation.operation_id' => [
            RepresentationPolicy::OPERATION_IDS,
            ['php/core/src/Extensions/BuiltIn/AttributeOverridesExtension.php'],
            ['$context->representation()->operationId'],
        ],
        'representation.enums.naming' => [
            RepresentationPolicy::ENUM_NAMINGS,
            ['php/core/src/Extensions/Schema/EnumDecoration.php'],
            ['$naming'],
        ],
        'representation.nullable' => [
            RepresentationPolicy::NULLABLE_STYLES,
            ['php/core/src/Extensions/Validation/FieldNode.php', 'php/core/src/Extensions/Schema/SchemaUnion.php'],
            ['$policy->nullable', '$policy'],
        ],
        'representation.filters' => [
            RepresentationPolicy::FILTER_STYLES,
            ['php/core/src/Extensions/Context/RepresentationPolicy.php'],
            ['$this->filterStyle'],
        ],
        'versioning' => [
            DocumentConfig::VERSIONING_POLICIES,
            ['php/core/src/Diff/Policy/VersioningPolicies.php', 'php/core/src/Versioning/VersionOrder.php'],
            ['$keyword'],
        ],
        'tags.default_strategy' => [
            DocumentConfig::TAG_STRATEGIES,
            ['php/core/src/Extensions/Context/DocumentConfig.php'],
            ['$this->tagDefaultStrategy()'],
        ],
        'error_responses' => [
            DocumentConfig::ERROR_RESPONSES,
            ['php/laravel/src/Extensions/ErrorResponsesExtension.php', 'php/laravel/src/Extensions/ImplicitResponsesExtension.php'],
            ['$context->document->errorResponses'],
        ],
        'on_route_error' => [
            DocumentConfig::ON_ROUTE_ERRORS,
            ['php/laravel/src/Pipeline/DocumentGenerator.php'],
            ['$document->onRouteError'],
        ],
        'viewer.source' => [
            ViewerConfig::SOURCES,
            ['php/laravel/src/Http/DocsController.php'],
            ['ViewerConfig::source($config->viewer)'],
        ],
    ];
}

it('names every value the product branches on in the set it publishes', function (string $setting, array $accepted, array $files, array $holders): void {
    $root = dirname(__DIR__, 4);
    $literals = [];

    foreach ($files as $file) {
        $literals = [...$literals, ...keywordLiteralsIn((string) file_get_contents($root.'/'.$file), $holders)];
    }

    expect(array_values(array_diff($literals, $accepted)))
        ->toBe([], $setting.' branches on values its published set leaves out')
        // A scan that matched nothing would agree with any set at all — including an empty one.
        ->and($literals)->not->toBe([], 'no branch on a '.$setting.' keyword was found in '.implode(', ', $files));
})->with(array_map(
    static fn (string $setting): array => [$setting, ...keywordBranchSites()[$setting]],
    array_combine(array_keys(keywordBranchSites()), array_keys(keywordBranchSites())),
));

/**
 * Every string literal one file compares or matches an expression against: `$x === 'a'`, `'a' !== $x`,
 * and the left-hand side of each arm of `match ($x)`, whose arms may name more than one value each.
 *
 * @param  list<string>  $holders  the expressions the keyword is held in, written as the code writes them
 * @return list<string>
 */
function keywordLiteralsIn(string $source, array $holders): array
{
    $literals = [];

    foreach ($holders as $holder) {
        $quoted = preg_quote($holder, '/');

        preg_match_all('/'.$quoted.'\s*(?:===|!==)\s*\'([^\']*)\'/', $source, $after);
        preg_match_all('/\'([^\']*)\'\s*(?:===|!==)\s*'.$quoted.'/', $source, $before);
        $literals = [...$literals, ...$after[1], ...$before[1]];

        foreach (matchBlocksOver($source, $holder) as $block) {
            preg_match_all('/(?:^|\n)\s*((?:\'[^\']*\'\s*,\s*)*\'[^\']*\')\s*=>/', $block, $arms);

            foreach ($arms[1] as $arm) {
                preg_match_all('/\'([^\']*)\'/', $arm, $values);
                $literals = [...$literals, ...$values[1]];
            }
        }
    }

    return array_values(array_unique($literals));
}

/**
 * The bodies of every `match (<holder>)` in the source, brace-balanced so an arm that opens a block of
 * its own does not end the search early.
 *
 * @return list<string>
 */
function matchBlocksOver(string $source, string $holder): array
{
    $needle = 'match ('.$holder.')';
    $blocks = [];
    $at = 0;

    while (($found = strpos($source, $needle, $at)) !== false) {
        $open = strpos($source, '{', $found);
        $at = $found + strlen($needle);

        if ($open === false) {
            continue;
        }

        $depth = 0;
        for ($cursor = $open; $cursor < strlen($source); $cursor++) {
            $depth += match ($source[$cursor]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                $blocks[] = substr($source, $open, $cursor - $open + 1);
                $at = $cursor;

                break;
            }
        }
    }

    return $blocks;
}

/**
 * The one-code decision, pinned: the family reports under a single code, and the two codes that used to
 * say the same thing under two names at two severities are gone.
 */
it('reports the whole family under one code', function (): void {
    expect(ConfiguredKeywords::CODE)->toBe('config.unknown-value');

    $sources = [dirname(__DIR__, 3).'/core/src', dirname(__DIR__, 2).'/src'];
    $retired = ['config.unknown-error-responses', 'config.unknown-tag-strategy'];

    $found = [];
    foreach ($sources as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if (! $entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($entry->getPathname());
            foreach ($retired as $code) {
                if (str_contains($source, $code)) {
                    $found[] = $code.' in '.$entry->getFilename();
                }
            }
        }
    }

    expect($found)->toBe([], 'codes retired into config.unknown-value that something still emits');
});
