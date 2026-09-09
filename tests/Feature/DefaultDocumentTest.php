<?php

declare(strict_types=1);

use Docuccino\Laravel\Config\ConfiguredDocuments;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\BuildSettings;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * A build always has a document, and every configuration state that leaves the `documents` bag empty
 * resolves to the one `default` document the shipped file describes.
 *
 * The invariant is what makes five diagnostics true. `config.file-unreadable`, `config.file-invalid`,
 * `config.file-not-a-map`, `config.file-misnamed` and `config.not-migrated` each tell their reader the
 * document was built from defaults alone — and each of those states leaves the bag empty, so with no
 * fallback the build loop ran zero times: nothing was written, and none of those sentences was said.
 * The commands exited 0 in silence.
 *
 * Stated over the states rather than over the reader, because the reader is one line and the states
 * are the population: each row here is a way an application arrives with no `documents` bag.
 *
 * The six then part in two. Three are configurations nobody wrote — no file, a file naming no
 * documents, a `documents` key the typed reader refused — and those BUILD the default document, which
 * is the product. Three are configurations somebody wrote that the build could not read, and a command
 * refuses those before it builds anything (`UnreadConfigRefusalTest`). Either way the document set is
 * resolved, which is what this file is about; what a command then does with it is what that one is.
 */

/**
 * The rows of {@see emptyDocumentBagStates()} a command refuses rather than builds: a configuration
 * its author wrote that the build could not read. Named here so the two halves cannot both claim a row
 * or both leave one out.
 *
 * @return list<string>
 */
function unreadableConfigStates(): array
{
    return [
        'settings still in the framework config',
        'a file that is not YAML',
        'a file holding a list',
    ];
}

/** @return array<string, array{Closure}> */
function emptyDocumentBagStates(): array
{
    return [
        // No file at all — a fresh install, and the state ConfigFile calls a legitimate one.
        'no configuration file' => [function (): void {
            BuildSettings::none();
        }],
        // No file, and the build settings still sitting in the framework config: the upgrade shape.
        'settings still in the framework config' => [function (): void {
            BuildSettings::none();
            config()->set('docuccino.documents.default.routes.include', ['api/*']);
            config()->set('docuccino.on_route_error', 'omit');
        }],
        // A file nothing can parse: a tab where YAML wants spaces.
        'a file that is not YAML' => [function (): void {
            BuildSettings::yaml("documents:\n\tdefault: {}\n");
        }],
        // A file that parses to something other than a map of settings.
        'a file holding a list' => [function (): void {
            BuildSettings::yaml("- one\n- two\n");
        }],
        // A file that parses and simply names no documents.
        'a file with no documents key' => [function (): void {
            BuildSettings::replace(['lint' => ['tags' => ['enabled' => true]]]);
        }],
        // `documents` written as a list, which the typed reader refuses rather than coercing.
        'documents written as a list' => [function (): void {
            BuildSettings::replace(['documents' => ['a', 'b']]);
        }],
    ];
}

it('resolves one default document out of a configuration that names none', function (Closure $arrange): void {
    $arrange();

    expect((new ConfiguredDocuments)->keys())->toBe(['default'])
        ->and((new ConfiguredDocuments)->has('default'))->toBeTrue()
        // The shipped file's own `default` bag, because that is the document this fallback claims to
        // be — see the invariant below, which is where the claim is held to.
        ->and((new ConfiguredDocuments)->raw('default'))->toBe(BuildSettings::shippedDocument())
        ->and(app(DocumentBuilder::class)->documentKeys())->toBe(['default']);
})->with(emptyDocumentBagStates());

it('gives a configuration that names no document what installing the shipped file would give it', function (): void {
    // The three values are written out rather than read back off the product, because the product is
    // what is on trial: the reference page states them as the defaults of `routes.include`,
    // `security.auth_middleware` and `error_responses`, and each of the three readers answers
    // something else for a bag that is simply empty — no route filter at all, no request treated as
    // authenticated, and no error responses. An empty fallback bag therefore published a document that
    // running `docuccino:install` — which writes those defaults and nothing else — would then have
    // CHANGED, taking routes out of the document the shipped file had just described.
    //
    // Publishing every route was the half with a consequence beyond surprise: an application with no
    // configuration got its web routes, its login and its debug endpoints in an API reference.
    BuildSettings::none();
    $fallback = app(DocumentBuilder::class)->config('default');

    expect($fallback->routeInclude)->toBe(['api/*'])
        ->and($fallback->authMiddleware)->toBe('auth*')
        ->and($fallback->errorResponses)->toBe('default');

    // And the whole bag, so a fourth setting that comes to disagree fails here rather than shipping.
    BuildSettings::boot();

    expect($fallback->raw)->toBe(app(DocumentBuilder::class)->config('default')->raw);
});

it('builds and writes that document rather than exiting 0 with nothing to show', function (Closure $arrange): void {
    // The measured failure this closes: `forEachDocument` over an empty list never entered its
    // closure, so `docuccino:export --fail-on=error` exited 0, printed nothing and wrote no file.
    //
    // The three states where nobody wrote a configuration the build failed to read. The other three
    // are refused instead, which is `UnreadConfigRefusalTest`'s half — including that nothing reaches
    // disk while they are.
    $arrange();
    bindStubEngine();

    $out = sys_get_temp_dir().'/docuccino-default-document-'.uniqid().'.json';

    try {
        test()->artisan('docuccino:export', ['--format' => 'uir', '--out' => $out]);

        expect(is_file($out))->toBeTrue()
            ->and((string) file_get_contents($out))->toContain('"title": "API Documentation"');
    } finally {
        @unlink($out);
    }
})->with(array_diff_key(emptyDocumentBagStates(), array_flip(unreadableConfigStates())));

it('publishes what the shipped route filter admits, and not the viewer routes beside them', function (): void {
    // The published axis and not only the resolved config, because that is where the cost was: with no
    // `routes.include` at all every route registered in the application was documented, and among them
    // were the documentation viewer's own four endpoints and Laravel's storage symlink route — in a
    // reference an API's consumers read.
    BuildSettings::none();
    bindStubEngine();

    $paths = array_keys(
        app(DocumentBuilder::class)->build('default', WorkbenchEngine::make())->document->toArray()['paths'] ?? [],
    );

    expect($paths)->not->toBeEmpty()
        ->and($paths)->each->toStartWith('/api/');
});

it('names the file error a reader has to fix, which no build could reach before', function (): void {
    // This prints from inside the build, so a build that never ran reported it nowhere: an application
    // with a malformed docuccino.yaml got exit 0 and complete silence.
    BuildSettings::yaml("documents:\n\tdefault: {}\n");
    bindStubEngine();

    expect(diagnosticsCoded(
        app(DocumentBuilder::class)->build('default', WorkbenchEngine::make())->diagnostics,
        'config.file-invalid',
    ))->toHaveCount(1);
});

it('names the unmigrated framework config from the command, on the population that diagnostic is for', function (): void {
    // The report that could not fire: `config.not-migrated` is raised inside the build, and an
    // unmigrated application had no document to build. The test that appeared to prove it called the
    // builder with a hard-coded 'default' and so never went through the configured set at all.
    BuildSettings::none();
    config()->set('docuccino.documents.default.routes.include', ['api/*']);
    config()->set('docuccino.on_route_error', 'omit');
    bindStubEngine();

    $out = sys_get_temp_dir().'/docuccino-unmigrated-'.uniqid().'.json';

    try {
        test()->artisan('docuccino:export', ['--format' => 'uir', '--out' => $out])
            ->expectsOutputToContain('config.not-migrated')
            ->assertExitCode(1);
    } finally {
        @unlink($out);
    }
});

it('leaves an application that declares its own documents alone', function (): void {
    // The fallback is for an EMPTY bag and nothing else: a multi-document application must not find a
    // `default` document it never declared sitting beside the two it did.
    BuildSettings::documents([
        'public' => ['info' => ['title' => 'Public', 'version' => '1.0.0']],
        'admin' => ['info' => ['title' => 'Admin', 'version' => '1.0.0']],
    ]);

    expect((new ConfiguredDocuments)->keys())->toBe(['public', 'admin'])
        ->and((new ConfiguredDocuments)->has('default'))->toBeFalse();
});
