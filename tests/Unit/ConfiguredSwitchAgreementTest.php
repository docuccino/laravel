<?php

declare(strict_types=1);

use Docuccino\Core\Content\ContentCompiler;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Context\ViewerContext;
use Docuccino\Core\Lint\MissingDescriptionLint;
use Docuccino\Core\Lint\SensitiveFieldLint;
use Docuccino\Laravel\Config\ConfiguredFlags;
use Docuccino\Laravel\Config\DocumentConfigFactory;
use Docuccino\Laravel\Config\LeakageOptions;
use Docuccino\Laravel\Pipeline\FragmentStore;
use Docuccino\Laravel\Viewer\ViewerPage;

/**
 * Every site that reads a configured on/off switch answers the SAME question the same way.
 *
 * The rule is stated here and nowhere read from the code: a switch is `true` or `false`. Either
 * answers itself; anything else — `'no'`, `'off'`, `'yes'`, `'on'`, `'1'`, `'0'`, `1`, `0`, `''`,
 * `null`, `[]` — answers that switch's OWN DEFAULT, and an absent key answers it too. No site
 * coerces, because `(bool) 'no'` is `true` and a coerced switch reads "no" as "on".
 *
 * Four readings used to divide these sites three ways over exactly that value: `is_bool($v) ? $v :
 * $default` took the default, `($v ?? $default) !== false` and `(bool) ($v ?? false)` both read
 * `'no'` as ON, and `$v === true` read it as OFF. Three ONs and one OFF, and no diagnostic anywhere.
 *
 * Each row below names a switch, states its default independently of the code, and INVOKES the real
 * reader — the container bind, the value object, the page — so a site that grows a second reading
 * fails here rather than in a released document.
 *
 * @return array<string, array{0: callable(mixed): bool, 1: bool}>
 */
function configuredSwitchSites(): array
{
    return [
        // ---- the four sites that disagreed ----------------------------------------------------
        'integrations.<name>.enabled (default-on)' => [
            fn (mixed $v): bool => switchDocument(['integrations' => ['eloquent' => ['enabled' => $v]]])
                ->integrationEnabled('eloquent', true),
            true,
        ],
        'integrations.<name>.enabled (opt-in)' => [
            fn (mixed $v): bool => switchDocument(['integrations' => ['permission' => ['enabled' => $v]]])
                ->integrationEnabled('permission', false),
            false,
        ],
        'lint.<rule>.enabled' => [
            function (mixed $v): bool {
                setBuild('lint.descriptions.enabled', $v);

                return switchLintEnabled(app(MissingDescriptionLint::class));
            },
            false,
        ],
        'cache.enabled' => [
            function (mixed $v): bool {
                setBuild('cache.enabled', $v);

                return app(FragmentStore::class)->enabled;
            },
            false,
        ],
        'viewer.cdn' => [
            fn (mixed $v): bool => str_contains(
                (new ViewerPage(new ViewerContext(switchDocument([], viewer: ['cdn' => $v]))))
                    ->scriptSrc('scalar', 'https://cdn.example.com/scalar.js'),
                'cdn.example.com',
            ),
            false,
        ],

        // ---- the rest of the class ------------------------------------------------------------
        'lint.leakage.enabled' => [
            fn (mixed $v): bool => LeakageOptions::fromConfig(['enabled' => $v])->enabled,
            true,
        ],
        'routes.include_vendor' => [
            fn (mixed $v): bool => app(DocumentConfigFactory::class)
                ->make('default', ['routes' => ['include_vendor' => $v]], 'skeleton')
                ->includeVendor,
            false,
        ],
        'representation.enums.components' => [
            fn (mixed $v): bool => RepresentationPolicy::fromConfig(['enums' => ['components' => $v]])->enumComponents,
            true,
        ],
        'representation.errors.components' => [
            fn (mixed $v): bool => RepresentationPolicy::fromConfig(['errors' => ['components' => $v]])->errorComponents,
            true,
        ],
        'representation.pagination.components' => [
            fn (mixed $v): bool => RepresentationPolicy::fromConfig(['pagination' => ['components' => $v]])->paginationComponents,
            true,
        ],
        'enabled (the master switch)' => [
            function (mixed $v): bool {
                config()->set('docuccino.enabled', $v);

                return ConfiguredFlags::enabled();
            },
            true,
        ],
        'nav.hidden (markdown frontmatter)' => [
            fn (mixed $v): bool => switchFrontmatterHidden($v),
            false,
        ],
    ];
}

/** The `enabled` a lint's bound options carry — the binding's own answer, not a re-read of the config. */
function switchLintEnabled(object $lint): bool
{
    /** @var object{enabled: bool} $options */
    $options = (new ReflectionProperty($lint, 'options'))->getValue($lint);

    return $options->enabled;
}

/** A document config carrying one raw bag, for the readers that take the whole config object. */
function switchDocument(array $raw, array $viewer = []): DocumentConfig
{
    return new DocumentConfig('default', [], viewer: $viewer, raw: $raw);
}

/** Whether a page whose frontmatter says `nav.hidden: <value>` compiles to a hidden page. */
function switchFrontmatterHidden(mixed $value): bool
{
    $dir = sys_get_temp_dir().'/docuccino-switch-'.bin2hex(random_bytes(8));
    mkdir($dir.'/content', recursive: true);
    file_put_contents(
        $dir.'/content/page.md',
        "---\nnav:\n  hidden: ".json_encode($value)."\n---\nBody.\n",
    );

    [$content] = (new ContentCompiler($dir))->compile(
        new DocumentConfig('default', [], raw: ['content' => ['dir' => 'content']]),
    );

    $hidden = $content->pages[0]->hidden;

    array_map('unlink', (array) glob($dir.'/content/*.md'));
    rmdir($dir.'/content');
    rmdir($dir);

    return $hidden;
}

/**
 * The values that will actually arrive. `true`/`false` are the two switches; every other row is a
 * value some author or `env()` has written where a switch belongs, and `no`/`off`/`yes`/`on` are the
 * ones a YAML parser hands back as strings.
 *
 * @return array<string, array{0: mixed, 1: ?bool}> value => the answer, or null for "that switch's default"
 */
function configuredSwitchValues(): array
{
    return [
        'true' => [true, true],
        'false' => [false, false],
        "'no'" => ['no', null],
        "'off'" => ['off', null],
        "'yes'" => ['yes', null],
        "'on'" => ['on', null],
        "'1'" => ['1', null],
        "'0'" => ['0', null],
        '1' => [1, null],
        '0' => [0, null],
        "''" => ['', null],
        'null' => [null, null],
        '[]' => [[], null],
    ];
}

it('answers every configured switch the same way, whatever the key holds', function (mixed $value, ?bool $answer): void {
    $sites = configuredSwitchSites();

    $disagreed = [];
    foreach ($sites as $name => [$read, $default]) {
        $expected = $answer ?? $default;
        $actual = $read($value);

        if ($actual !== $expected) {
            $disagreed[] = sprintf(
                '%s read %s as %s, expected %s',
                $name,
                var_export($value, true),
                $actual ? 'true' : 'false',
                $expected ? 'true' : 'false',
            );
        }
    }

    // A row list that stopped listing would agree with anything.
    expect($sites)->toHaveCount(12)
        ->and($disagreed)->toBe([]);
})->with(configuredSwitchValues());

it('answers every configured switch its own default when nobody wrote the key', function (): void {
    expect(RepresentationPolicy::fromConfig([])->enumComponents)->toBeTrue()
        ->and(RepresentationPolicy::fromConfig([])->errorComponents)->toBeTrue()
        ->and(RepresentationPolicy::fromConfig([])->paginationComponents)->toBeTrue()
        ->and(RepresentationPolicy::fromConfig(['enums' => []])->enumComponents)->toBeTrue()
        ->and(LeakageOptions::fromConfig([])->enabled)->toBeTrue()
        ->and(switchDocument([])->integrationEnabled('eloquent', true))->toBeTrue()
        ->and(switchDocument([])->integrationEnabled('permission', false))->toBeFalse()
        ->and(switchDocument(['integrations' => ['eloquent' => []]])->integrationEnabled('eloquent', true))->toBeTrue()
        ->and(app(DocumentConfigFactory::class)->make('default', [], 'skeleton')->includeVendor)->toBeFalse()
        ->and(switchFrontmatterHiddenAbsent())->toBeFalse();
});

/** A frontmatter block that states no `hidden` at all. */
function switchFrontmatterHiddenAbsent(): bool
{
    $dir = sys_get_temp_dir().'/docuccino-switch-'.bin2hex(random_bytes(8));
    mkdir($dir.'/content', recursive: true);
    file_put_contents($dir.'/content/page.md', "---\ntitle: Page\n---\nBody.\n");

    [$content] = (new ContentCompiler($dir))->compile(
        new DocumentConfig('default', [], raw: ['content' => ['dir' => 'content']]),
    );

    $hidden = $content->pages[0]->hidden;

    array_map('unlink', (array) glob($dir.'/content/*.md'));
    rmdir($dir.'/content');
    rmdir($dir);

    return $hidden;
}

/**
 * The catalogue that REPORTS a refusal has to name the same default the reader USES, or the
 * diagnostic tells the author a value was used that was not. Stated here from the contract: an
 * opt-out switch is on until someone turns it off, an opt-in one is off until someone turns it on.
 */
it('reports the default each reader actually used', function (): void {
    // `viewer` is passed ONLY as the viewer bag, and deliberately not planted in the raw bag too: the
    // raw bag is `docuccino.yaml`'s document, which has no `viewer` in it, and the viewer bag is the
    // framework config's. A refusal looked for in the wrong one of the two can never fire, and a
    // document carrying it in both would let that pass.
    $document = switchDocument([
        'routes' => ['include_vendor' => 'no'],
        'representation' => [
            'enums' => ['components' => 'no'],
            'errors' => ['components' => 'no'],
            'pagination' => ['components' => 'no'],
        ],
        'integrations' => [
            'eloquent' => ['enabled' => 'no'],
            'permission' => ['enabled' => 'no'],
        ],
    ], viewer: ['cdn' => 'no']);

    $said = [];
    foreach (ConfiguredFlags::forDocument($document) as $diagnostic) {
        preg_match('/^(\S+) is .* read as (true|false)/', $diagnostic->message, $matches);
        $said[$matches[1]] = $matches[2] === 'true';
    }

    expect($said)->toBe([
        'representation.enums.components' => true,
        'representation.errors.components' => true,
        'representation.pagination.components' => true,
        'routes.include_vendor' => false,
        'viewer.cdn' => false,
        'integrations.eloquent.enabled' => true,
        'integrations.permission.enabled' => false,
    ]);
});

it('finds no viewer switch in the bag the other file fills', function (): void {
    // The failure this replaced: `viewer.cdn` was looked for in the raw bag, which stopped carrying a
    // `viewer` when the build started reading `docuccino.yaml` — so a refused value in
    // `config/docuccino.php` was silently used as its default. A `viewer` written into the raw bag is
    // not configuration at all, and reporting one would tell an author to fix a file that says nothing.
    expect(ConfiguredFlags::forDocument(switchDocument(['viewer' => ['cdn' => 'no']])))->toBe([]);
});

/**
 * `lint.<rule>.enabled` defaults are a hand-written table, and a rule bound with no entry in it
 * would have no default to read. The source of truth for which rules exist is the shipped config
 * file, so the table is held against that rather than against itself.
 */
it('holds a lint default for every lint rule the shipped config declares', function (): void {
    require_once dirname(__DIR__, 4).'/tools/config-reference-sync.php';

    // Read out of the BUILD configuration, because that is where lint lives: `config/docuccino.php`
    // keeps only what boot reads, and asking it for lint rules would agree with an empty table.
    $settings = (string) file_get_contents(dirname(__DIR__, 2).'/config/'.CONFIG_REFERENCE_SETTINGS);

    $declared = [];
    foreach (config_reference_yaml_keys($settings) as $key) {
        if (preg_match('/^lint\.([a-z_]+)\.enabled$/', $key, $matches) === 1) {
            $declared[] = $matches[1];
        }
    }

    sort($declared);
    $table = array_keys(ConfiguredFlags::LINT_DEFAULTS);
    sort($table);

    // A reader that stopped matching would agree with an empty table.
    expect($declared)->toHaveCount(7)
        ->and($table)->toBe($declared);
});

it('keeps the sensitive-field lint reading the same switch', function (): void {
    setBuild('lint.leakage.enabled', 'no');

    // 'no' is refused, so the lint keeps its default — which for leakage is ON.
    expect(switchLintEnabled(app(SensitiveFieldLint::class)))->toBeTrue()
        // The redaction half never honours the switch at all, which is a different rule and stays one.
        ->and(LeakageOptions::fromConfig(['enabled' => false], honourSwitch: false)->enabled)->toBeTrue();
});
