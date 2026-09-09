<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Laravel\Config\ConfigSplit;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\BuildSettings;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * What the build says about the two configuration files, whose severity is the SITUATION and not the
 * keys: an application with build settings in `config/docuccino.php` and no `docuccino.yaml` has not
 * been migrated and gets an error, one that has both gets a warning naming what to delete, and one
 * that configures nothing at all gets silence.
 *
 * Both directions of the document-id comparison are exercised here, including the silent one. The
 * silence is load-bearing rather than an omission: a document with no viewer is an export-only
 * document, and if both directions fired, one renamed id would produce two diagnostics pointing
 * opposite ways.
 */
function configSplitDiagnostics(string $code): array
{
    bindStubEngine();

    return diagnosticsCoded(
        app(DocumentBuilder::class)->build('default', WorkbenchEngine::make())->diagnostics,
        $code,
    );
}

// --- Migration: no docuccino.yaml, and build settings left in the framework config -----------------

it('refuses to build an application whose settings are all still in the framework config', function (): void {
    BuildSettings::none();
    config()->set('docuccino.documents.default.routes.include', ['api/*']);
    config()->set('docuccino.on_route_error', 'omit');

    $errors = configSplitDiagnostics('config.not-migrated');

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->severity)->toBe(Severity::Error)
        // An error, not a warning: a document built from defaults here would be plausible AND wrong —
        // the routes, the info and the security this application configured are silently gone.
        ->and($errors[0]->message)->toContain('There is no docuccino.yaml')
        ->and($errors[0]->message)->toContain('2 settings the build no longer reads')
        ->and($errors[0]->message)->toContain('documents.default.routes')
        ->and($errors[0]->message)->toContain('on_route_error')
        // The remedy names the command that carries the settings over, and not the one that publishes
        // defaults: `docuccino:install` would write a file with none of these in it.
        ->and($errors[0]->help)->toContain('docuccino:migrate-config');
});

it('names the two files one report each, not one per key, and counts what it does not name', function (): void {
    BuildSettings::none();

    // Twelve settings, which is an ordinary size for an application mid-migration and four more than
    // a sentence-embedded list will name.
    foreach (['info', 'servers', 'routes', 'security', 'error_responses', 'tags', 'content', 'overlays', 'representation', 'versioning', 'integrations', 'export'] as $key) {
        config()->set('docuccino.documents.default.'.$key, []);
    }

    $errors = configSplitDiagnostics('config.not-migrated');

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->message)->toContain('12 settings')
        ->and($errors[0]->message)->toContain('and 4 more')
        // Name order, so the list is the same list however the file happens to be arranged.
        ->and($errors[0]->message)->toContain('documents.default.content, documents.default.error_responses');
});

// --- Leftovers: docuccino.yaml is there, and the framework config still carries build settings ------

it('warns that build settings beside a configuration file were ignored', function (): void {
    config()->set('docuccino.lint.descriptions.enabled', true);

    $warnings = configSplitDiagnostics('config.stale-php-keys');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->severity)->toBe(Severity::Warning)
        ->and($warnings[0]->message)->toContain('1 setting the build does not read')
        ->and($warnings[0]->message)->toContain('lint')
        ->and($warnings[0]->help)->toContain('Nothing there is merged over docuccino.yaml');
});

it('really does not read them', function (): void {
    // The warning's whole claim. `descriptions` is off in the shipped file, and turning it on in the
    // framework config has to change nothing — a merge layer would show up here as a lint report.
    config()->set('docuccino.lint.descriptions.enabled', true);

    bindStubEngine();
    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    expect(diagnosticsCoded($result->diagnostics, 'lint.missing-description'))->toBe([]);
});

it('says nothing about a framework config trimmed to what the framework reads', function (): void {
    // Exactly the shape the shipped file will have: the master switch, viewer wiring, the cache store.
    config()->set('docuccino', [
        'enabled' => true,
        'documents' => ['default' => ['viewer' => ['route' => '/docs/api']]],
        'cache' => ['store' => null],
    ]);

    expect(ConfigSplit::staleKeys())->toBe([])
        ->and(configSplitDiagnostics('config.stale-php-keys'))->toBe([])
        ->and(configSplitDiagnostics('config.not-migrated'))->toBe([]);
});

it('publishes the four codes this file names, under those names', function (): void {
    // The codes are written out as literals above, because a diagnostic code is a published contract:
    // an application accepts one by name in `diagnostics.accept`, and the reference page has a row per
    // name. This is the one place the literals and the constants are held together.
    expect([
        ConfigSplit::NOT_MIGRATED,
        ConfigSplit::STALE_KEYS,
        ConfigSplit::VIEWER_ORPHAN,
        ConfigSplit::VIEWER_COLLISION,
    ])->toBe([
        'config.not-migrated',
        'config.stale-php-keys',
        'config.viewer-orphan',
        'config.viewer-route-collision',
    ]);
});

it('leaves the whole suite standing on the split ConfigSplit describes', function (): void {
    // The suite boots on the shipped `config/docuccino.php` untouched, so this asks the real reader
    // about the real file: a build key back in it would make every test in the suite report a warning
    // none of them is about. Stated literally, because a guard that asked ConfigSplit what it owns
    // would agree with a bug that widened it. This is also the one assertion that a DEFAULT INSTALL is
    // silent — the shipped framework config trips neither of the two migration diagnostics.
    expect(ConfigSplit::FRAMEWORK_KEYS)->toBe(['enabled', 'cache.store', 'documents.*.viewer'])
        ->and(ConfigSplit::staleKeys())->toBe([]);
});

// --- Zero configuration: neither file says anything ------------------------------------------------

it('says nothing at all about an application that configures nothing', function (): void {
    BuildSettings::none();
    config()->set('docuccino', []);

    bindStubEngine();
    $result = app(DocumentBuilder::class)->build('default', WorkbenchEngine::make());

    // No configuration is a supported state, and a fresh install would otherwise meet a diagnostic
    // about a file it has no reason to have written yet.
    expect(diagnosticsCoded($result->diagnostics, 'config.not-migrated'))->toBe([])
        ->and(diagnosticsCoded($result->diagnostics, 'config.stale-php-keys'))->toBe([])
        ->and(diagnosticsCoded($result->diagnostics, 'config.viewer-orphan'))->toBe([]);
});

// --- The two files keyed by the same document ids --------------------------------------------------

it('warns about a viewer configured for a document the build does not define', function (): void {
    config()->set('docuccino.documents.admin.viewer', ['route' => '/docs/admin']);

    $warnings = configSplitDiagnostics('config.viewer-orphan');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->severity)->toBe(Severity::Warning)
        ->and($warnings[0]->message)->toContain('1 document docuccino.yaml does not define')
        ->and($warnings[0]->message)->toContain('admin')
        // Boot cannot know, so it registers the routes and the request is what fails. This is the only
        // place that can say so before somebody clicks the link.
        ->and($warnings[0]->message)->toContain('every request to them fails');
});

it('escapes the route it says two documents collide on', function (): void {
    // The route is the application's own text, and a diagnostic message is not only printed: a build
    // diagnostic reaches `x-docuccino.diagnostics` in the emitted document, where a `jq -r` re-arms an
    // escape sequence that survived as bytes. The two document names in the same sentence go through
    // `NameList`, which escapes them — this one sat raw between them.
    config()->set('docuccino.documents', [
        'default' => ['viewer' => ['route' => "docs\x1b[31m/api"]],
        'admin' => ['viewer' => ['route' => "docs\x1b[31m/api"]],
    ]);
    BuildSettings::set('documents.admin', ['info' => ['title' => 'Admin', 'version' => '1.0.0']]);

    $warnings = configSplitDiagnostics('config.viewer-route-collision');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->message)->not->toContain("\x1b")
        ->and($warnings[0]->message)->toContain('\x1B')
        // And a route with nothing to escape still reads as the author wrote it, or the report names a
        // setting nobody can find.
        ->and($warnings[0]->message)->toContain('/api');
});

it('says nothing about a document that configures no viewer, which is an ordinary shape', function (): void {
    BuildSettings::set('documents.exports-only', ['info' => ['title' => 'Exports', 'version' => '1.0.0']]);

    // The silence is deliberate. An export-only document has no page, and if this direction fired too,
    // renaming one document id would produce two diagnostics pointing opposite ways.
    expect(configSplitDiagnostics('config.viewer-orphan'))->toBe([]);
});

it('warns when two documents claim one viewer route', function (): void {
    config()->set('docuccino.documents', [
        'default' => ['viewer' => ['route' => '/docs/api']],
        'admin' => ['viewer' => ['route' => 'docs/api']],
    ]);
    BuildSettings::set('documents.admin', ['info' => ['title' => 'Admin', 'version' => '1.0.0']]);

    $warnings = configSplitDiagnostics('config.viewer-route-collision');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->severity)->toBe(Severity::Warning)
        // Both spellings of one route collide: the framework registers `/docs/api` either way, and
        // keeps only the last of the two.
        ->and($warnings[0]->message)->toContain('/docs/api')
        ->and($warnings[0]->message)->toContain('default, admin')
        ->and($warnings[0]->message)->toContain('keeps only the last one registered');
});

it('says nothing when every viewer route is its own', function (): void {
    config()->set('docuccino.documents', [
        'default' => ['viewer' => ['route' => '/docs/api']],
        'admin' => ['viewer' => ['route' => '/docs/admin']],
    ]);
    BuildSettings::set('documents.admin', ['info' => ['title' => 'Admin', 'version' => '1.0.0']]);

    expect(configSplitDiagnostics('config.viewer-route-collision'))->toBe([]);
});
