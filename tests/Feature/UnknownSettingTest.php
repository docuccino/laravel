<?php

declare(strict_types=1);

use Docuccino\Core\Config\ConfigFile;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Config\ConfigSplit;
use Docuccino\Laravel\Config\DeclaredSettings;
use Docuccino\Laravel\Config\UnknownSettings;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\BuildSettings;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Symfony\Component\Yaml\Yaml;

/**
 * `config.unknown-setting`: a key in `docuccino.yaml` that names no setting, and the one Docuccino key
 * it was probably meant to be.
 *
 * The report has two ways to be wrong and they are not symmetric. Missing a typo leaves the silence it
 * replaces; firing on CORRECT configuration is worse, because the reader is sent to fix a file that is
 * already right and the channel stops being worth reading. So the firing population is measured on
 * four real corpora below and has to be zero on all four, and every hit on a typo has to name
 * something its reader can act on.
 */

/** The report over one file's bytes, read the way a project's file is read. */
function unknownSettings(string $yaml): array
{
    return UnknownSettings::report(new BuildConfig(ConfigFile::parse($yaml)));
}

/** @return list<string> */
function unknownSettingKeys(string $yaml): array
{
    return array_map(
        static fn ($diagnostic): string => explode(' ', $diagnostic->message)[0],
        unknownSettings($yaml),
    );
}

// --- Where it fires, and what it says -------------------------------------------------------------

it('names the key, and the one key it was probably meant to be', function (string $yaml, string $key, string $help): void {
    $report = unknownSettings($yaml);

    expect($report)->toHaveCount(1)
        ->and($report[0]->code)->toBe('config.unknown-setting')
        // A warning, not info: this is not a switch nobody reads being ignored, it is an instruction
        // somebody wrote being discarded, and it cannot fire on anything but a key an author typed.
        ->and($report[0]->severity)->toBe(Severity::Warning)
        ->and($report[0]->message)->toStartWith($key.' names no setting')
        ->and($report[0]->help)->toContain($help);
})->with([
    // A misspelling: the suggestion is drawn from the keys at the SAME place in the file.
    'a root key' => ["documnets:\n  default:\n    info: { title: 'X' }\n", 'documnets', 'Did you mean documents?'],
    'a lint rule' => ["lint:\n  desriptions:\n    enabled: true\n", 'lint.desriptions', 'Did you mean lint.descriptions?'],
    // The suggestion is spelled with the author's own document key back in, not with the wildcard the
    // comparison is made against — they have to be able to find the line they typed.
    'a leaf inside a document' => [
        "documents:\n  api:\n    routes:\n      includ: ['api/*']\n",
        'documents.api.routes.includ',
        'Did you mean documents.api.routes.include?',
    ],
    'a knob under a known integration' => [
        "documents:\n  default:\n    integrations:\n      sanctum: { enabld: true }\n",
        'documents.default.integrations.sanctum.enabld',
        'Did you mean documents.default.integrations.sanctum.enabled?',
    ],
    'a member of an export target' => [
        "documents:\n  default:\n    export:\n      targets:\n        - { format: 'full', pth: 'a.json' }\n",
        'documents.default.export.targets.0.pth',
        'Did you mean documents.default.export.targets.0.path?',
    ],
    // A block at the wrong indentation, which is the mistake YAML makes that framework config could
    // not: the key is spelled right and has been REPARENTED, so the answer is where it belongs.
    'a reparented top-level bag' => [
        "documents:\n  default:\n    info: { title: 'X' }\n    lint:\n      tags: { enabled: true }\n",
        'documents.default.lint',
        'The setting called lint sits at lint',
    ],
    'a reparented engine bag' => [
        "documents:\n  default:\n    engine: { mode: 'null' }\n",
        'documents.default.engine',
        'The setting called engine sits at engine',
    ],
    // A key that belongs to the OTHER file. Not a guess at all, so it is answered before either guess.
    'the master switch' => ["enabled: true\n", 'enabled', 'read from config/docuccino.php'],
    'the cache store' => ["cache:\n  store: 'redis'\n", 'cache.store', 'read from config/docuccino.php'],
    'a viewer bag' => [
        "documents:\n  default:\n    viewer: { route: '/docs' }\n",
        'documents.default.viewer',
        'read from config/docuccino.php',
    ],
    // Nothing like anything: the page, rather than an invention.
    'a word that is nothing like a setting' => [
        "flurble:\n  wibble: 3\n",
        'flurble',
        'check it against the configuration reference',
    ],
    'a member of a closed OAS-shaped list' => [
        "documents:\n  default:\n    tags:\n      definitions:\n        - { name: 'Billing', titel: 'B' }\n",
        'documents.default.tags.definitions.0.titel',
        'check it against the configuration reference',
    ],
    // Under a workflow ID the keys are Docuccino's again, so the name being the author's stops at the
    // ID. Silencing the whole subtree instead would leave a misspelled `summary` publishing nothing
    // and saying nothing, which is the one failure this report exists to replace.
    'a workflow\'s own prose key' => [
        "documents:\n  default:\n    workflows:\n      placeOrder: { sumary: 'Place an order' }\n",
        'documents.default.workflows.placeOrder.sumary',
        'Did you mean documents.default.workflows.placeOrder.summary?',
    ],
    // The near miss is drawn from what the file DECLARES, never from what it illustrates: the answer
    // here used to be "the setting called scheme sits at documents.*.security.schemes.bearer.scheme",
    // which describes an example scheme as a setting and buries the one-edit answer.
    'a key one edit from an author-keyed bag' => [
        "documents:\n  default:\n    security:\n      scheme:\n        bearer: { type: 'http' }\n",
        'documents.default.security.scheme',
        'Did you mean documents.default.security.schemes?',
    ],
    // The "sits at" sentence outranks the guess below it because it states a FACT, so it may not name
    // a field inside a list ENTRY: nothing indents a bag to `tags.definitions.*.name`, because an
    // entry is written with `- `. A guess, marked as one, is the honest answer there.
    'a key unique to a list entry field' => [
        "documents:\n  default:\n    name: 'Public API'\n",
        'documents.default.name',
        'Did you mean documents.default.tags?',
    ],
    // The same population where nothing is near enough to guess at either: the page, rather than an
    // address the author could not write even having been sent to it.
    'a list entry field with no near miss' => [
        "documents:\n  default:\n    weight: 3\n",
        'documents.default.weight',
        'check it against the configuration reference',
    ],
]);

it('reports a reparented bag once, not once per rule under it', function (): void {
    // An unknown key is not descended into. Four rules under a misindented `lint:` are one mistake,
    // and a channel that fires five times for one edit is a channel its reader learns to skip.
    $report = unknownSettings(<<<'YAML'
        documents:
          default:
            lint:
              tags: { enabled: true }
              leakage: { enabled: false }
              descriptions: { enabled: true, allow: ['GET /api/ping'] }
        YAML);

    expect($report)->toHaveCount(1)
        ->and($report[0]->message)->toStartWith('documents.default.lint names no setting');
});

it('says nothing about a key another diagnostic already owns', function (): void {
    // `integrations.eloqent` is `config.unknown-integration`'s report, and it names the near miss too.
    // Two reports on one typo, one level apart, teach a reader to skim both.
    expect(unknownSettingKeys("documents:\n  default:\n    integrations:\n      eloqent: { enabled: true }\n"))->toBe([]);

    BuildSettings::set('documents.default.integrations', ['eloqent' => ['enabled' => true]]);
    bindStubEngine();

    expect(diagnosticsCoded(
        app(DocumentBuilder::class)->build('default', WorkbenchEngine::make())->diagnostics,
        'config.unknown-integration',
    ))->toHaveCount(1);
});

// --- Where it must NOT fire -----------------------------------------------------------------------

it('says nothing about the keys an author names for themselves', function (string $yaml): void {
    expect(unknownSettingKeys($yaml))->toBe([]);
})->with([
    // Verbatim OpenAPI: every field of the Info and Server objects is published as written, so a key
    // this reader has never heard of is the consumer's, not a mistake.
    'other OAS info fields' => ["documents:\n  default:\n    info:\n      title: 'X'\n      contact: { name: 'Ops' }\n      license: { name: 'MIT', identifier: 'MIT' }\n"],
    'server objects' => ["documents:\n  default:\n    servers:\n      - { url: 'https://api.example.com', variables: { region: { default: 'eu' } } }\n"],
    // Names the author chooses, each holding an OAS object or the author's own value.
    'security scheme names' => ["documents:\n  default:\n    security:\n      schemes:\n        myScheme: { type: 'http', scheme: 'bearer' }\n"],
    'security requirements' => ["documents:\n  default:\n    security:\n      document: [{ myScheme: ['read'] }]\n"],
    'tag map entries' => ["documents:\n  default:\n    tags:\n      map: { Invoice: 'Billing', 'App\\\\Widget': 'Widgets' }\n"],
    'leakage patterns' => ["lint:\n  leakage:\n    patterns: { sortcode: 'a bank sort code' }\n"],
    'example formats' => ["documents:\n  default:\n    representation:\n      examples:\n        formats: { uuid: 'a-b-c' }\n"],
    'filter descriptions' => ["documents:\n  default:\n    integrations:\n      query_builder:\n        filter_descriptions: { exact: 'Matches %field%.' }\n"],
    // A workflow ID is a name the application chose with `#[WorkflowStep]`, and `checkout` is only
    // what the shipped file shows one looking like. Every other ID used to be reported while the
    // prose under it was read and published — the report contradicting the build.
    'workflow ids' => ["documents:\n  default:\n    workflows:\n      placeOrder: { summary: 'Place an order' }\n      refund: { description: 'Give the money back.' }\n"],
    // And `inputs` is a JSON Schema, published as the Arazzo workflow's own `inputs`: its keywords
    // are the spec's vocabulary, so they are no more ours to judge than an OAS Server Object is.
    'workflow inputs' => ["documents:\n  default:\n    workflows:\n      placeOrder:\n        inputs: { type: 'object', properties: { basketId: { type: 'string' } }, required: ['basketId'] }\n"],
    // A document key is a name too, and so is a list index.
    'document keys' => ["documents:\n  public: { info: { title: 'Public' } }\n  internal: { info: { title: 'Internal' } }\n"],
    'list entries' => ["documents:\n  default:\n    overlays: ['a.yaml', 'b.yaml']\n    routes: { include: ['api/*'], exclude: ['api/internal/*'] }\n"],
    // Nothing configured is a supported state, and so is a file that would not parse: neither has a
    // key to report, and the file's own diagnostic already says why the rest is missing.
    'an empty file' => [''],
    'a file that is not YAML' => ["documents:\n\tdefault: {}\n"],
]);

// --- The two counts -------------------------------------------------------------------------------

it('fires nowhere on the file this package ships', function (): void {
    // Population one, and the one that matters most: `docuccino:install` writes these bytes, so a hit
    // here would fire on every fresh install.
    expect(unknownSettingKeys(BuildSettings::shipped()))->toBe([]);
});

it('fires nowhere on the configuration the suite itself runs', function (): void {
    // Population two: the workbench app, whose configuration is the shipped file with the permission
    // integration opted in — and, transitively, the ~11000 tests that build on it.
    bindStubEngine();

    expect(diagnosticsCoded(
        app(DocumentBuilder::class)->build('default', WorkbenchEngine::make())->diagnostics,
        'config.unknown-setting',
    ))->toBe([]);
});

it('fires nowhere on the configuration the website tells people to write', function (): void {
    // Population three: every `yaml` block in the docs that is Docuccino configuration, read from the
    // path its section documents. These are the files readers copy, so a hit is a docs bug — and a
    // scan that matched none of them would pass forever, so the block and key counts are pinned too.
    [$blocks, $keys, $hits] = documentedSettingBlocks();

    expect($hits)->toBe([])
        ->and($blocks)->toBeGreaterThan(20)
        ->and($keys)->toBeGreaterThan(150);
});

/**
 * The docs' own configuration blocks, as [block count, key count, unknown keys].
 *
 * A block is read from whichever declared path leaves it fewest unknown keys, because the pages quote
 * fragments in context — `info:` with its members under it, under the heading that documents it. A
 * block where NOTHING resolves is not configuration at all (a route file, an overlay) and is skipped.
 *
 * @return array{int, int, list<string>}
 */
function documentedSettingBlocks(): array
{
    $root = dirname(__DIR__, 4).'/website/src/content/docs';
    $bases = ['', ...DeclaredSettings::shipped()];
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && in_array($file->getExtension(), ['md', 'mdx'], true)) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    $blocks = 0;
    $keys = 0;
    $hits = [];

    foreach ($files as $path) {
        if (preg_match_all('/^```ya?ml.*?\n(.*?)^```/ms', (string) file_get_contents($path), $matches) !== 1
            && $matches[1] === []) {
            continue;
        }

        foreach ($matches[1] as $block) {
            $best = null;

            foreach ($bases as $base) {
                try {
                    $declared = DeclaredSettings::of($block, $base);
                } catch (Throwable) {
                    continue;
                }

                if ($declared === []) {
                    continue;
                }

                $unknown = array_values(array_filter($declared, unknownDocumentedKey(...)));

                if ($best === null || count($unknown) < count($best[1])) {
                    $best = [$declared, $unknown];
                }
            }

            if ($best === null || count($best[1]) === count($best[0])) {
                continue;
            }

            $blocks++;
            $keys += count($best[0]);

            foreach ($best[1] as $key) {
                $hits[] = str_replace($root.'/', '', $path).': '.$key;
            }
        }
    }

    return [$blocks, $keys, $hits];
}

/** Whether a documented key path is one the product would report. */
function unknownDocumentedKey(string $path): bool
{
    if (in_array($path, DeclaredSettings::shipped(), true)) {
        return false;
    }

    foreach (UnknownSettings::OPEN as $open) {
        if (str_starts_with($path, $open.'.')) {
            return false;
        }
    }

    foreach (array_keys(UnknownSettings::DEFERRED) as $deferred) {
        if (preg_match('/^'.preg_quote($deferred, '/').'\.[^.]+$/', $path) === 1) {
            return false;
        }
    }

    return true;
}

// --- The derivation, and the guards on it ---------------------------------------------------------

it('derives the settings surface from the file this package ships', function (): void {
    // No hand-maintained list: the shipped file IS the declaration, and it is already held to the
    // website's configuration reference key for key. A scan that stopped seeing its shapes would
    // report every key in every project, so the floor is stated here as well as in the tool's guard.
    $declared = DeclaredSettings::shipped();

    expect(count($declared))->toBeGreaterThan(120)
        // A commented option is still an option — most of the file is commented — and a reader that
        // skipped them would report a key the shipped file itself offers.
        ->and($declared)->toContain('documents.*.api_version.header')
        ->and($declared)->toContain('documents.*.webhooks.dir')
        ->and($declared)->toContain('documents.*.integrations.permission.enabled')
        ->and($declared)->toContain('lint.unpinned_redirect.allow')
        ->and($declared)->toContain('engine.memory_limit')
        // …and a live one, so the reader is not only reading comments.
        ->and($declared)->toContain('on_route_error')
        ->and($declared)->toContain('cache.enabled');
});

it('reads the shipped file from where install copies it', function (): void {
    expect(DeclaredSettings::path())->toEndWith('/php/laravel/config/'.ConfigFile::NAME)
        ->and(is_file(DeclaredSettings::path()))->toBeTrue();
});

it('leaves every framework-owned key unreadable from this file, and says so', function (): void {
    // The mirror of `config.stale-php-keys`: that one covers a build setting left in the framework
    // config, and this covers a framework setting written into the build file. Between them the two
    // files' whole surface is covered in both directions, so a key in the wrong file is never silent.
    // One needle per call: `toContain()` takes NEEDLES, not a message, so `not->toContain($key, $why)`
    // inverts a two-needle call and passes the moment either is absent — and the reason string never
    // appears in a key list, so it passed whatever the shipped file declared.
    foreach (ConfigSplit::FRAMEWORK_KEYS as $key) {
        expect(in_array($key, DeclaredSettings::shipped(), true))
            ->toBeFalse($key.' is declared in the build file');
    }
});

it('says whose a key written anywhere in the shipped file is', function (): void {
    // What went wrong before this guard: `documents.*.workflows` was missing from OPEN, and the only
    // thing holding the lists together asserted AUTHOR_KEYED ⊆ OPEN — two lists that were short in the
    // same way, agreeing with each other. A guard derived from a SUBSET is silent outside it.
    //
    // So the denominator is the DOMAIN, derived from the file: everywhere a key can be WRITTEN, which
    // is every section plus every value the file shows as a bag. Both halves are needed and the second
    // is where the second half of this defect lived — `workflows.*.inputs` is a bag and not a section,
    // so a denominator of sections alone would have said nothing about the subtree that opened it.
    //
    // What each row is worth is not uniform, and saying so is the point. `a setting` and
    // `an integration` are SETTLED here — the first by the report firing, the second by the subtree
    // being one this report defers ({@see UnknownSettings::DEFERRED}), which names the code that
    // covers it. `a name` and `verbatim` are both silence and the report cannot tell them apart: the
    // file spells `security.schemes.bearer` and `info.title` identically, which is the same fact that
    // made the original defect invisible. Those two rows record a decision for a reader rather than
    // proving one. What the totality buys is that a new place to write a key cannot be silent without
    // somebody having written down which of the four it is.
    $whose = [
        'cache' => 'a setting',
        'diagnostics' => 'a setting',
        // The document key itself is a name; everything under one is ours again.
        'documents' => 'a name',
        'documents.*' => 'a setting',
        'documents.*.api_version' => 'a setting',
        'documents.*.content' => 'a setting',
        'documents.*.coverage' => 'a setting',
        'documents.*.examples' => 'a setting',
        'documents.*.export' => 'a setting',
        // The OAS Info Object: `title` and `version` are ours, and every other field is published as
        // written, so the whole object is passed through.
        'documents.*.info' => 'verbatim',
        'documents.*.info.description' => 'verbatim',
        // Each member is an integration name, reported by `config.unknown-integration` instead.
        'documents.*.integrations' => 'an integration',
        'documents.*.integrations.api_resources' => 'a setting',
        'documents.*.integrations.eloquent' => 'a setting',
        'documents.*.integrations.json_api_paginate' => 'a setting',
        'documents.*.integrations.laravel_actions' => 'a setting',
        'documents.*.integrations.passport' => 'a setting',
        'documents.*.integrations.permission' => 'a setting',
        'documents.*.integrations.query_builder' => 'a setting',
        // Filter kind => your own sentence.
        'documents.*.integrations.query_builder.filter_descriptions' => 'a name',
        'documents.*.integrations.rate_limit' => 'a setting',
        'documents.*.integrations.sanctum' => 'a setting',
        'documents.*.integrations.spatie_data' => 'a setting',
        'documents.*.integrations.timacdonald_json_api' => 'a setting',
        'documents.*.representation' => 'a setting',
        'documents.*.representation.enums' => 'a setting',
        'documents.*.representation.errors' => 'a setting',
        'documents.*.representation.examples' => 'a setting',
        // JSON Schema `format` => your own sample.
        'documents.*.representation.examples.formats' => 'a name',
        'documents.*.representation.pagination' => 'a setting',
        'documents.*.routes' => 'a setting',
        'documents.*.security' => 'a setting',
        // An OAS Security Requirement: the key is a scheme name of yours, so it is a bag rather than
        // a section and would fall outside a denominator drawn from sections alone.
        'documents.*.security.default.*.bearer' => 'verbatim',
        'documents.*.security.document.*.bearer' => 'verbatim',
        // Your scheme names, each holding an OAS Security Scheme Object. The two below it are the
        // examples the shipped file writes, so the object's own fields are passed through.
        'documents.*.security.schemes' => 'a name',
        'documents.*.security.schemes.apiKey' => 'verbatim',
        'documents.*.security.schemes.bearer' => 'verbatim',
        'documents.*.tags' => 'a setting',
        // Raw tag => display tag.
        'documents.*.tags.map' => 'a name',
        'documents.*.webhooks' => 'a setting',
        // The workflow ID your `#[WorkflowStep]` attributes declare…
        'documents.*.workflows' => 'a name',
        // …and `summary`, `description` and `inputs` under it, which are ours.
        'documents.*.workflows.*' => 'a setting',
        // …while what is written inside `inputs` is a JSON Schema, whose keywords are the spec's.
        'documents.*.workflows.*.inputs' => 'verbatim',
        'engine' => 'a setting',
        'lint' => 'a setting',
        'lint.descriptions' => 'a setting',
        'lint.examples' => 'a setting',
        'lint.leakage' => 'a setting',
        // Token => label heuristics.
        'lint.leakage.patterns' => 'a name',
        'lint.operation_ids' => 'a setting',
        'lint.tags' => 'a setting',
        'lint.unpinned_redirect' => 'a setting',
        'lint.vacuous_union' => 'a setting',
    ];

    // Everywhere a key can be written, off the file: a section holds keys, and a value the file shows
    // as a BAG is a map somebody writes keys into. Sorted the way the reader sorts, so a row out of
    // place is as loud as a row missing.
    $bags = array_keys(array_filter(
        DeclaredSettings::valueTypes(),
        static fn (string $type): bool => $type === DeclaredSettings::BAG,
    ));
    $written = array_values(array_unique([...DeclaredSettings::sections(), ...$bags]));
    sort($written);

    expect(array_keys($whose))->toBe($written)
        // Four answers and no fifth: an unknown label reads as "not a setting" to the loop below and
        // would say nothing about why, which is the silence this guard exists to remove.
        ->and(array_values(array_unique(array_values($whose))))
        ->toEqualCanonicalizing(['a setting', 'a name', 'verbatim', 'an integration']);

    foreach ($whose as $where => $answer) {
        // One invented key written there. A `*` is given a name under a keyed map and a one-entry LIST
        // anywhere else, which is what the two kinds of `*` mean — the same reading the product makes
        // when it decides whether a path is somewhere a key can be addressed at all.
        $node = ['no_such_setting_anywhere' => 'x'];
        $typed = [];

        foreach (array_reverse(explode('.', $where)) as $index => $segment) {
            $list = $segment === '*'
                && DeclaredSettings::addressesListEntry(implode('.', array_slice(explode('.', $where), 0, -$index)));

            $node = $list ? [$node] : [$segment === '*' ? 'a_name_the_author_chose' : $segment => $node];
            $typed[] = $list ? '0' : ($segment === '*' ? 'a_name_the_author_chose' : $segment);
        }

        $literal = implode('.', array_reverse($typed)).'.no_such_setting_anywhere';

        // Settled: reported exactly where the key would have been Docuccino's.
        expect(unknownSettingKeys(Yaml::dump($node, 20)))->toBe(
            $answer === 'a setting' ? [$literal] : [],
            $where.' answers as '.$answer,
        );

        // Settled too, and against a different fact: `an integration` claims another code covers the
        // name, so the subtree has to be one this report really defers — and nothing else may be.
        expect(array_key_exists($where, UnknownSettings::DEFERRED))
            ->toBe($answer === 'an integration', $where.' answers as '.$answer);
    }
});

it('names each open subtree for a reason, and none of them broadly', function (): void {
    // An OPEN entry is silence, so the list is stated literally here rather than read back off the
    // class: a bug that widened it would otherwise agree with itself. Every entry is a place where a
    // Docuccino key and an author's key are indistinguishable.
    expect(UnknownSettings::OPEN)->toBe([
        'documents.*.info',
        'documents.*.servers',
        'documents.*.security.schemes',
        'documents.*.security.default',
        'documents.*.security.document',
        'documents.*.tags.map',
        'documents.*.workflows.*.inputs',
        'lint.leakage.patterns',
        'documents.*.integrations.query_builder.filter_descriptions',
        'documents.*.representation.examples.formats',
    ]);

    // No entry may be a prefix of the whole file, or of a document bag: either would take the report
    // out with it. And each has to still name a subtree the file declares — an entry that outlived the
    // setting it was written for is silence over nothing, and nothing else here would see it.
    foreach (UnknownSettings::OPEN as $open) {
        expect(substr_count($open, '.'))->toBeGreaterThan(0, $open.' opens a whole top-level bag')
            ->and(in_array($open, DeclaredSettings::shipped(), true))
            ->toBeTrue($open.' opens a subtree the shipped file no longer declares');
    }
});

it('is open wherever the documentation guard is opaque', function (): void {
    // Two lists, two purposes: the repo's sync guard skips a subtree because the reference page owes
    // it no rows, and this report skips one because nothing below it is Docuccino's to judge. Every
    // author-supplied subtree is both, so one list must contain the other — and the difference is
    // named here rather than left to be discovered.
    require_once dirname(__DIR__, 4).'/tools/config-reference-sync.php';

    expect(array_values(array_diff(CONFIG_REFERENCE_OPAQUE, UnknownSettings::OPEN)))->toBe([])
        // `info` is the one subtree that declares keys AND passes the rest through: the page documents
        // `info.title` and `info.version`, so the sync guard must keep checking them.
        ->and(array_values(array_diff(UnknownSettings::OPEN, CONFIG_REFERENCE_OPAQUE)))->toBe(['documents.*.info']);
});

it('defers only to a diagnostic that really covers those names', function (): void {
    expect(UnknownSettings::DEFERRED)->toBe(['documents.*.integrations' => 'config.unknown-integration']);

    // And the code named is one the packages actually emit, so a rename cannot leave this report
    // deferring to nothing.
    require_once dirname(__DIR__, 4).'/tools/diagnostic-codes.php';

    $root = dirname(__DIR__, 4);
    $emitted = diagnostic_codes([$root.'/php/core/src', $root.'/php/laravel/src']);

    foreach (UnknownSettings::DEFERRED as $subtree => $code) {
        expect(array_key_exists($code, $emitted))->toBeTrue($subtree.' defers to a code nothing emits');
    }
});
