<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\ConfiguredKeywords;
use Docuccino\Laravel\Config\DeclaredSettings;
use Docuccino\Laravel\Config\ViewerConfig;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\BuildSettings;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/*
 * The refusal of a closed-set keyword, executed rather than asserted — and executed for EVERY keyword
 * setting there is, against one rule written out here rather than read back off the readers:
 *
 *   A value outside a keyword setting's set must leave the build reading that setting as the value the
 *   SHIPPED configuration shows beside it, and must raise exactly one warning, coded
 *   config.unknown-value, whose message names the setting by the path an author writes, quotes what
 *   they wrote, and names what was read instead — with help listing every value the setting takes.
 *
 * Two settings in this family used to report, at two severities under two names, and four reported
 * nothing at all. Covering them separately is what let that stand: a guard over what each reader does
 * proves nothing about whether they answer ALIKE, so the settings are driven through one dataset and
 * held to one sentence.
 */

/** The build's diagnostics under this family's code. */
function keywordRefusals(): array
{
    return array_values(array_filter(
        app(DocumentBuilder::class)->build('default', app(TypeEngine::class))->diagnostics,
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'config.unknown-value',
    ));
}

/**
 * Every keyword setting, as [the path an author writes, how a value is written to it, what reads it
 * back]. One row per catalogued setting — the next test holds this list to the catalogue, so a keyword
 * setting added without a row here fails rather than going unexercised.
 *
 * The reader is the PRODUCT's own reading of the setting, asked through the finished document config:
 * a diagnostic that named a fallback the build did not actually use would be checkable and wrong, and
 * that is the half this catches.
 *
 * @return array<string, array{0: string, 1: Closure(mixed): void, 2: Closure(DocumentConfig): string}>
 */
function keywordSettingRows(): array
{
    $representation = static fn (string $member): Closure => static fn (DocumentConfig $config): string => (string) RepresentationPolicy::fromConfig($config->representation)->{$member};

    return [
        'documents.*.error_responses' => [
            'error_responses',
            static function (mixed $value): void {
                setBuild('documents.default.error_responses', $value);
            },
            static fn (DocumentConfig $config): string => $config->errorResponses,
        ],
        'documents.*.representation.enums.naming' => [
            'representation.enums.naming',
            static function (mixed $value): void {
                setBuild('documents.default.representation.enums.naming', $value);
            },
            $representation('enumNaming'),
        ],
        'documents.*.representation.filters' => [
            'representation.filters',
            static function (mixed $value): void {
                setBuild('documents.default.representation.filters', $value);
            },
            $representation('filterStyle'),
        ],
        'documents.*.representation.nullable' => [
            'representation.nullable',
            static function (mixed $value): void {
                setBuild('documents.default.representation.nullable', $value);
            },
            $representation('nullable'),
        ],
        'documents.*.representation.operation_id' => [
            'representation.operation_id',
            static function (mixed $value): void {
                setBuild('documents.default.representation.operation_id', $value);
            },
            $representation('operationId'),
        ],
        'documents.*.tags.default_strategy' => [
            'tags.default_strategy',
            static function (mixed $value): void {
                setBuild('documents.default.tags.default_strategy', $value);
            },
            static fn (DocumentConfig $config): string => $config->tagDefaultStrategy(),
        ],
        'documents.*.versioning' => [
            'versioning',
            static function (mixed $value): void {
                setBuild('documents.default.versioning', $value);
            },
            static fn (DocumentConfig $config): string => $config->versioning,
        ],
        // The one out of the framework config: the viewer is read on a REQUEST, so it is configured
        // there — and the build is still what reports it, because a request has nowhere to put a report.
        'documents.*.viewer.source' => [
            'viewer.source',
            static function (mixed $value): void {
                config()->set('docuccino.documents.default.viewer.source', $value);
            },
            static fn (DocumentConfig $config): string => ViewerConfig::source($config->viewer),
        ],
        'on_route_error' => [
            'on_route_error',
            static function (mixed $value): void {
                setBuild('on_route_error', $value);
            },
            static fn (DocumentConfig $config): string => $config->onRouteError,
        ],
    ];
}

/**
 * What the shipped configuration shows beside one keyword setting — read off the bytes an install
 * writes, not off the constant being checked.
 */
function shippedKeywordDefault(string $path): string
{
    if (str_starts_with($path, 'documents.*.viewer.')) {
        return (string) config('docuccino.documents.default.'.substr($path, strlen('documents.*.')));
    }

    $node = DeclaredSettings::shippedTree();
    foreach (explode('.', str_replace('documents.*.', 'documents.default.', $path)) as $segment) {
        $node = is_array($node) ? ($node[$segment] ?? null) : null;
    }

    return (string) (is_string($node) ? $node : '');
}

beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

it('exercises every keyword setting the catalogue reads', function (): void {
    // The dataset below only proves the rows it lists, so the list is held to the catalogue rather than
    // trusted: a keyword setting added with no row here would otherwise pass this file in silence.
    expect(array_keys(keywordSettingRows()))->toBe(array_keys(ConfiguredKeywords::catalogue()));
});

it('answers the shipped default and reports it, the same way, for every keyword setting', function (
    string $path,
    string $written,
    Closure $configure,
    Closure $read,
): void {
    $default = shippedKeywordDefault($path);
    $configure('wibble');

    $found = keywordRefusals();
    $config = app(DocumentBuilder::class)->config('default');

    expect($found)->toHaveCount(1, $path.' reported '.count($found).' refusals rather than one')
        ->and($found[0]->severity)->toBe(Severity::Warning)
        // Spelled out rather than read off the class: it is a name people put in `diagnostics.accept`,
        // so renaming it silently breaks their configuration.
        ->and($found[0]->code)->toBe('config.unknown-value')
        ->and($found[0]->message)->toBe(sprintf(
            '%s is the text "wibble", which is none of the values it takes — it is read as "%s", its default.',
            $written,
            $default,
        ))
        ->and($found[0]->help)->toStartWith('Write one of: ')
        // The other half of the promise: the document was BUILT on the default the message names.
        ->and($read($config))->toBe($default);
})->with(array_map(
    static fn (string $path): array => [$path, ...keywordSettingRows()[$path]],
    array_combine(array_keys(keywordSettingRows()), array_keys(keywordSettingRows())),
));

/**
 * The types YAML hands back where a keyword was meant. Every one of these is a value somebody typed,
 * and every one of them used to be read as the default in silence — `1.10` is the float 1.1, a bare
 * `no` is the string `'no'`, and a key written with nothing after the colon arrives as null.
 */
it('refuses every shape a mistyped keyword arrives in, and says which it was', function (mixed $value, string $found): void {
    setBuild('documents.default.representation.nullable', $value);

    $refusals = keywordRefusals();

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0]->message)->toBe(
            'representation.nullable is '.$found.', which is none of the values it takes'
            .' — it is read as "type-array", its default.',
        )
        ->and(RepresentationPolicy::fromConfig(app(DocumentBuilder::class)->config('default')->representation)->nullable)
        ->toBe('type-array');
})->with([
    'a near miss' => ['anyOf', 'the text "anyOf"'],
    'a word YAML keeps as text' => ['no', 'the text "no"'],
    'a decimal' => [1.1, 'the decimal number 1.1'],
    'a boolean' => [true, 'the boolean true'],
    'a written null' => [null, 'empty'],
]);

it('reports every refused keyword, not just the first', function (): void {
    setBuild('documents.default.versioning', 'smever');
    setBuild('documents.default.representation.filters', 'deepobject');
    setBuild('on_route_error', 'ommit');

    expect(keywordRefusals())->toHaveCount(3);
});

/**
 * The firing population on a healthy install: zero. A diagnostic that fires on a configuration nobody
 * has touched trains people to ignore the channel and takes the useful diagnostics with it — so this
 * is the assertion that keeps it from becoming that, against the bytes `docuccino:install` writes.
 */
it('says nothing about the keywords the shipped configuration ships with', function (): void {
    expect(keywordRefusals())->toBe([]);
});

/** And nothing about the keywords written out in full, every one of them, in every accepted spelling. */
it('says nothing about a configuration whose keywords are keywords', function (): void {
    foreach (keywordSettingRows() as $path => [, $configure]) {
        foreach (ConfiguredKeywords::catalogue()[$path][0] as $keyword) {
            $configure($keyword);

            expect(keywordRefusals())->toBe([], $path.' refused its own accepted value '.$keyword);
        }

        $configure(shippedKeywordDefault($path));
    }
});

/**
 * A key nobody wrote is silence, because it has expressed no intent. Only a key with an author behind
 * it is ever refused — which is what makes every hit in this family actionable.
 */
it('says nothing about a keyword setting no document writes', function (): void {
    $settings = BuildSettings::settings();
    unset(
        $settings['documents']['default']['error_responses'],
        $settings['documents']['default']['tags']['default_strategy'],
        $settings['documents']['default']['representation'],
        $settings['documents']['default']['versioning'],
        $settings['on_route_error'],
    );
    BuildSettings::replace($settings);

    $config = app(DocumentBuilder::class)->config('default');

    expect(keywordRefusals())->toBe([])
        ->and($config->versioning)->toBe(DocumentConfig::VERSIONING_DEFAULT)
        ->and($config->onRouteError)->toBe(DocumentConfig::ON_ROUTE_ERROR_DEFAULT)
        ->and($config->tagDefaultStrategy())->toBe(DocumentConfig::TAG_STRATEGY_DEFAULT)
        // The one setting whose absent reading is NOT its refused reading: a document that never
        // mentions error_responses publishes none, where one that names something unreadable gets the
        // shipped `default`.
        ->and($config->errorResponses)->toBe(DocumentConfig::ERROR_RESPONSES_ABSENT);
});
