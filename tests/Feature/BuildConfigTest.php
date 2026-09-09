<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Config\BuildConfig;
use Docuccino\Laravel\Config\ConfiguredFlags;
use Docuccino\Laravel\Config\ConfiguredKeywords;
use Docuccino\Laravel\Config\ConfiguredShapes;
use Docuccino\Laravel\Config\DeclaredSettings;
use Docuccino\Laravel\Config\UnknownSettings;
use Docuccino\Laravel\Config\UnusableRouteFilterException;
use Docuccino\Laravel\Engine\TypeEngineMode;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\BuildSettings;
use Docuccino\Laravel\Tests\Support\SettingReadingTransformer;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Docuccino\Laravel\Watch\ArtisanBuildRunner;

/*
 * The adapter reading `docuccino.yaml`: that a build's report carries what the READER refused as well
 * as what the build itself found, and that the two environment variables which used to be `env()`
 * calls in the framework config still override the one setting each.
 */

/**
 * Set one environment variable for the body and take it away again, whatever the body does. A leaked
 * variable is not a tidiness problem here: the next test in this file reads the same two names, and
 * would be answering the last one's question.
 */
function withDocuccinoEnv(string $name, string $value, Closure $body): void
{
    putenv($name.'='.$value);

    try {
        $body();
    } finally {
        putenv($name);
    }
}

it('reports what the configuration reader refused, in the build that read it', function (): void {
    // A refusal is only useful where somebody sees it, and nothing at a container bind has anywhere to
    // put one. `1.10` is the canonical case: YAML reads it as the float 1.1, and a converting reader
    // would publish "1.1" — a different version number in whatever client is generated from this.
    BuildSettings::yaml(<<<'YAML'
        documents:
          default:
            info:
              title: 'Forms API'
              version: 1.10
            routes:
              include: ['api/forms']
        YAML);

    bindStubEngine();
    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());
    $refusals = diagnosticsCoded($result->diagnostics, 'config.value-type');

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0]->severity)->toBe(Severity::Warning)
        ->and($refusals[0]->message)->toContain('documents.default.info.version')
        ->and($refusals[0]->help)->toContain('Quote it')
        // And the document says the default rather than the number the parser made up.
        ->and($result->document->toArray()['info']['version'] ?? null)->toBe('1.0.0');
});

// --- A key that holds something other than what the file declares ------------------------------

/**
 * Every key the shipped file declares, as one row each: the whole domain, so nothing can fall between
 * a key this pass asks about and one it deliberately says nothing about.
 *
 * @return array<string, array{string}>
 */
function declaredKeys(): array
{
    return array_combine(
        DeclaredSettings::shipped(),
        array_map(static fn (string $path): array => [$path], DeclaredSettings::shipped()),
    );
}

/** A value of the wrong shape for `$path`, so writing it there is always a defect. */
function wrongShapeFor(string $path): mixed
{
    // Text for anything that takes a collection or a number, a boolean for anything that takes text,
    // and the trap spelling for a switch: YAML reads `yes` as the STRING "yes", not as true.
    return match (DeclaredSettings::valueTypes()[$path] ?? null) {
        DeclaredSettings::TEXT => true,
        DeclaredSettings::SWITCH_TYPE => 'yes',
        default => 'nope',
    };
}

/** Whether `$path` addresses an entry of a list rather than a key an author writes. */
function isListEntry(string $path): bool
{
    $segments = explode('.', $path);

    foreach ($segments as $index => $segment) {
        if ($segment === '*' && ($index === 0 || ! in_array($segments[$index - 1], DeclaredSettings::KEYED_MAPS, true))) {
            return true;
        }
    }

    return false;
}

/** Whether `$path` sits below a subtree whose member names are the author's. */
function isBelowAuthorKeyed(string $path): bool
{
    foreach (ConfiguredShapes::AUTHOR_KEYED as $open) {
        if (str_starts_with($path, $open.'.')) {
            return true;
        }
    }

    return false;
}

it('refuses a declared key holding the wrong shape, over every key the shipped file declares', function (string $path): void {
    // The same ground ConfigFile refuses a whole FILE that is not a map on: the build would otherwise
    // run on every default and produce a plausible document, so the author's file looks applied and is
    // not. It does not weaken further down the tree, and `routes` is where it bites hardest — a glob
    // written at the section OR at `include` leaves no route filter at all, and the author who wrote a
    // filter gets every route in the application.
    //
    // Over the declared set rather than a sample of it, because a key added to the shipped file and not
    // to whatever asks about it would go straight back to degrading in silence. Each row states its own
    // expectation, so a key that owes no refusal says so here rather than falling in a gap.
    BuildSettings::only($path, wrongShapeFor($path));
    bindStubEngine();

    try {
        $diagnostics = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make())->diagnostics;
    } catch (UnusableRouteFilterException $refused) {
        // Some configuration stops the run outright rather than warning: the route set you would get
        // by skipping a filter you explicitly wrote is a superset of the one you narrowed to. That is
        // a report too, and a louder one, so it settles the row.
        expect($refused->diagnostic->code)->toBe('config.route-filter-unusable');

        return;
    }

    $refusals = diagnosticsCoded($diagnostics, 'config.value-type');
    $named = str_replace('*', 'default', $path);
    $type = DeclaredSettings::valueTypes()[$path] ?? null;

    // A list ENTRY is not a key, nothing below an author-keyed subtree is ours to judge, and the keys
    // in UNSHAPED each carry their reason there. None of the three owes a type refusal.
    if (isListEntry($path) || isBelowAuthorKeyed($path) || array_key_exists($path, ConfiguredShapes::UNSHAPED)) {
        expect($refusals)->toBe([]);

        return;
    }

    // A closed-set keyword is the same: one reading, one report, and the report names the set the
    // value missed rather than the type it was — which is the more useful of the two, so the shape
    // pass stands aside for it.
    if (array_key_exists($path, ConfiguredKeywords::catalogue())) {
        expect($refusals)->toBe([])
            ->and(diagnosticsCoded($diagnostics, ConfiguredKeywords::CODE))->toHaveCount(1);

        return;
    }

    // A switch has one reading and one report wherever it sits, so a second one would be a second line
    // to fix for one mistake.
    if ($type === DeclaredSettings::SWITCH_TYPE) {
        expect($refusals)->toBe([])
            ->and(diagnosticsCoded($diagnostics, ConfiguredFlags::CODE))->toHaveCount(1);

        return;
    }

    // A bag's keys are the author's, and a key the file ships as `null` states no type — there is
    // nothing to hold either to. NUMBER is here for completeness and stands empty: see below.
    if (in_array($type, [DeclaredSettings::BAG, DeclaredSettings::NONE, DeclaredSettings::NUMBER], true)) {
        expect($refusals)->toBe([]);

        return;
    }

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0]->severity)->toBe(Severity::Warning)
        ->and($refusals[0]->message)->toStartWith($named.' is ')
        ->and($refusals[0]->message)->toContain(match (true) {
            in_array($path, DeclaredSettings::sections(), true) => 'the setting takes a map of settings',
            $type === DeclaredSettings::TEXT => 'the setting takes text',
            default => 'the setting takes a list',
        });
})->with(declaredKeys());

it('reads what it asks about off the shipped file, by shape', function (): void {
    // The dataset above is only worth what this answers, so a plausible minimum stands beside it: a
    // reader that stopped recognising shapes would leave every row of it vacuous and green.
    //
    // The rules are written out rather than read back off the file. A SECTION is a map with keys under
    // it; a list holds entries and contributes the same `*` segment a keyed map does, so a set derived
    // from the dotted paths alone would demand a map where the shipped file itself shows a list. A
    // VALUE takes the shape the file writes there, and the two empty collections are the pair that
    // cannot be told apart once parsed — `exclude: []` is a list and `map: {}` is a bag.
    expect(count(DeclaredSettings::sections()))->toBeGreaterThan(40)
        ->and(count(DeclaredSettings::values()))->toBeGreaterThan(80)
        ->and(DeclaredSettings::sections())
        ->toContain('documents.*.routes')
        ->toContain('documents.*.security')
        ->toContain('lint.leakage')
        ->toContain('documents.*.integrations.query_builder')
        ->not->toContain('documents.*.export.targets')
        ->not->toContain('documents.*.tags.definitions')
        ->not->toContain('documents.*.routes.include')
        ->not->toContain('on_route_error');

    $types = DeclaredSettings::valueTypes();

    expect($types['documents.*.routes.include'] ?? null)->toBe(DeclaredSettings::LIST)
        ->and($types['documents.*.routes.exclude'] ?? null)->toBe(DeclaredSettings::LIST)
        ->and($types['documents.*.export.targets'] ?? null)->toBe(DeclaredSettings::LIST)
        ->and($types['documents.*.tags.map'] ?? null)->toBe(DeclaredSettings::BAG)
        ->and($types['engine.mode'] ?? null)->toBe(DeclaredSettings::TEXT)
        ->and($types['lint.leakage.enabled'] ?? null)->toBe(DeclaredSettings::SWITCH_TYPE)
        ->and($types['cache.path'] ?? null)->toBe(DeclaredSettings::NONE);

    // Nothing asks for a whole number, because the file's only one sits inside a list ENTRY and no key
    // addresses it. Stated here rather than assumed: a reachable one appearing would fail this and the
    // dataset above together, instead of going quietly unasked behind an arm nobody wrote.
    expect(array_keys(array_filter($types, static fn (string $type): bool => $type === DeclaredSettings::NUMBER)))
        ->toBe(['documents.*.tags.definitions.*.weight']);
});

it('splits the declared surface in two with nothing over and nothing in between', function (): void {
    // Two guards side by side cover their two subsets and nothing between them, so the union is what
    // is asserted: every key the file declares is either a section or a value, and none is both.
    $sections = DeclaredSettings::sections();
    $values = DeclaredSettings::values();

    expect(array_intersect($sections, $values))->toBe([])
        ->and(count($sections) + count($values))->toBe(count(DeclaredSettings::shipped()))
        // And every value carries a type, so no key is silent for want of an answer.
        ->and(array_keys(DeclaredSettings::valueTypes()))->toEqualCanonicalizing($values);
});

it('says nothing about only the keys it names, and names only real ones', function (): void {
    // A table entry that stopped naming a real key would excuse nothing while still reading as a
    // considered decision, and an author-keyed subtree has to be one this pass would otherwise walk
    // into — every one of them is a section of ours holding names of theirs.
    expect(array_keys(ConfiguredShapes::UNSHAPED))->each->toBeIn(DeclaredSettings::shipped())
        ->and(ConfiguredShapes::AUTHOR_KEYED)->each->toBeIn(UnknownSettings::OPEN);

    foreach (ConfiguredShapes::AUTHOR_KEYED as $open) {
        expect($open)->toBeIn(DeclaredSettings::sections());
    }
});

it('leaves a closed-set keyword to the reader that names the set, and asks the rest', function (): void {
    // The shape pass skips the keyword catalogue rather than listing those keys, so the skip is held to
    // still matching something: a catalogue that stopped naming declared paths would put every keyword
    // setting back under a second reader, and one line to fix would be reported twice under two codes.
    $keywords = array_keys(ConfiguredKeywords::catalogue());
    $declared = array_intersect($keywords, DeclaredSettings::shipped());

    expect(count($declared))->toBeGreaterThanOrEqual(8)
        // And no key is claimed by two tables at once, which would make which one wins an accident.
        ->and(array_intersect($keywords, array_keys(ConfiguredShapes::UNSHAPED)))->toBe([]);

    // Every one of them is a key the pass would otherwise have asked, so the skip is load-bearing
    // rather than decorative.
    $types = DeclaredSettings::valueTypes();
    foreach ($declared as $path) {
        expect($types[$path] ?? null)->toBeIn([DeclaredSettings::TEXT, DeclaredSettings::LIST]);
    }
});

it('reports a key once, however many readers walk through it', function (): void {
    // `documents.default` is asked about by the shape pass, and every setting the build reads under it
    // walks through the same key. One defect is one line to go and fix.
    BuildSettings::yaml("documents:\n  default: 'nope'\n");
    bindStubEngine();

    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    expect(diagnosticsCoded($result->diagnostics, 'config.value-type'))->toHaveCount(1)
        // And the document says the defaults, which is what the refusal claims was used instead.
        ->and($result->document->toArray()['info']['title'] ?? null)->toBe('API Documentation');
});

it('names the route filter it dropped, at the key that names it', function (): void {
    // The one row of the table above whose remedy is louder than a warning: a filter that cannot be
    // applied stops the run, because the route set you would get by skipping it is a superset you
    // explicitly narrowed. So the shape pass says nothing and this is what a reader sees instead.
    BuildSettings::only('documents.default.routes.filter', true);
    bindStubEngine();

    expect(fn (): mixed => app(DocumentBuilder::class)->build('default', WorkbenchEngine::make()))
        ->toThrow(UnusableRouteFilterException::class);
});

it('reports a setting refused while the build ran, not only the ones read before it', function (): void {
    // A setting is refused where it is READ, and an extension's hooks run inside the build. Collected
    // before generating, the report was missing exactly the refusals the build itself provoked — the
    // same shape as a diagnostic raised while building and lost on a warm cache hit, one layer out.
    BuildSettings::set('extensions', [SettingReadingTransformer::class]);
    BuildSettings::set(SettingReadingTransformer::SETTING, true);
    bindStubEngine();

    $refusals = diagnosticsCoded(
        app(DocumentBuilder::class)->build('default', WorkbenchEngine::make())->diagnostics,
        'config.value-type',
    );

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0]->message)->toStartWith(SettingReadingTransformer::SETTING.' is the boolean true, ');
});

it('reports a configuration file that is not a map at all', function (): void {
    BuildSettings::yaml("- documents\n- lint\n");

    bindStubEngine();
    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    expect(diagnosticsCoded($result->diagnostics, 'config.file-not-a-map'))->toHaveCount(1);
});

it('refuses a documents section written as a list rather than inventing documents from it', function (): void {
    BuildSettings::yaml(<<<'YAML'
        documents:
          - default
        YAML);

    expect(app(BuildConfig::class)->documents())->toBe([])
        ->and(app(BuildConfig::class)->values()->diagnostics()[0]->message)
        ->toContain('documents is a list, where the setting takes a map of settings');
});

/**
 * `on_route_error` names one of two behaviours, so it is read as the closed set it is rather than as
 * text that happens to be compared against two words. That is why a wrong-typed value is reported by
 * the keyword catalogue and not by the typed reader: the setting's defect is never "this is not text",
 * it is "this is not one of skeleton and omit" — and the advice a reader can act on is the two values,
 * where "quote it" would leave a quoted typo behaving exactly as the unquoted one did. Reported ONCE,
 * for the same reason: two readers refusing one key is two pieces of advice about one line.
 */
it('reads on_route_error off the file as a keyword, and refuses anything else once', function (): void {
    BuildSettings::set('on_route_error', 'omit');
    expect(app(DocumentBuilder::class)->config('default')->onRouteError)->toBe('omit');

    BuildSettings::set('on_route_error', true);

    $refusals = diagnosticsCoded(
        app(DocumentBuilder::class)->build('default', WorkbenchEngine::make())->diagnostics,
        'config.unknown-value',
    );

    expect(app(DocumentBuilder::class)->config('default')->onRouteError)->toBe('skeleton')
        ->and($refusals)->toHaveCount(1)
        ->and($refusals[0]->message)->toBe(
            'on_route_error is the boolean true, which is none of the values it takes'
            .' — it is read as "skeleton", its default.',
        )
        ->and($refusals[0]->help)->toBe('Write one of: "skeleton", "omit".')
        // And the typed reader keeps out of it, so the author is sent to one line with one answer.
        ->and(diagnosticsCoded(app(BuildConfig::class)->values()->diagnostics(), 'config.value-type'))->toBe([]);
});

// --- The two environment levers -------------------------------------------------------------------

it('lets the environment override the one setting each variable names, and nothing else', function (): void {
    // The closed list, stated literally: a run has an opinion about these two, and everything else
    // belongs in the file where it can be reviewed and committed.
    expect(BuildConfig::ENV_OVERRIDES)->toBe([
        'DOCUCCINO_ENGINE' => 'engine.mode',
        'DOCUCCINO_FRAGMENT_CACHE' => 'cache.enabled',
    ]);
});

it('switches inference off from the environment, in the spelling the diagnostics ask for', function (): void {
    // Four diagnostics' help text tells a reader to set this, and the framework reads the word `null`
    // as PHP null — so a lever that passed that through would leave the mode unrecognised while every
    // one of those four sentences claimed otherwise.
    BuildSettings::set('engine.mode', 'in-process');

    withDocuccinoEnv(BuildConfig::ENGINE_MODE, 'null', function (): void {
        expect(app(BuildConfig::class)->engine()['mode'])->toBe(TypeEngineMode::Null->value);
    });
});

it('turns the fragment cache on from the environment, which is how a watch session does it', function (): void {
    // `docuccino:watch` sets this for the builds it drives rather than editing anybody's file.
    BuildSettings::set('cache.enabled', false);

    withDocuccinoEnv(ArtisanBuildRunner::FRAGMENT_CACHE, 'true', function (): void {
        expect(app(BuildConfig::class)->cache()['enabled'])->toBeTrue();
    });
});

it('leaves the file alone when the environment says nothing', function (): void {
    BuildSettings::set('engine.mode', 'null');
    BuildSettings::set('cache.enabled', true);

    expect(app(BuildConfig::class)->engine()['mode'])->toBe('null')
        ->and(app(BuildConfig::class)->cache()['enabled'])->toBeTrue();
});

it('hands a value the environment set on to the reader that owns the setting, unconverted', function (): void {
    // `1` is not a switch, and this is not the place that decides what to do about that: the flag
    // catalogue refuses it and names it, exactly as it would a `1` written in the file.
    BuildSettings::set('cache.enabled', false);

    withDocuccinoEnv(ArtisanBuildRunner::FRAGMENT_CACHE, '1', function (): void {
        expect(app(BuildConfig::class)->cache()['enabled'])->toBe('1');
    });
});
