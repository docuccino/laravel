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
        "documents:\n  default:\n    export:\n      targets:\n        - { format: 'uir', pth: 'a.json' }\n",
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
    foreach (ConfigSplit::FRAMEWORK_KEYS as $key) {
        expect(DeclaredSettings::shipped())->not->toContain($key, $key.' is declared in the build file');
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
        'lint.leakage.patterns',
        'documents.*.integrations.query_builder.filter_descriptions',
        'documents.*.representation.examples.formats',
    ]);

    // No entry may be a prefix of the whole file, or of a document bag: either would take the report
    // out with it.
    foreach (UnknownSettings::OPEN as $open) {
        expect(substr_count($open, '.'))->toBeGreaterThan(0, $open.' opens a whole top-level bag');
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
