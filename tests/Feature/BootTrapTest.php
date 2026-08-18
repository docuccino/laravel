<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Facades\Docuccino;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Tests\Support\LateBoundMarker;
use Docuccino\Laravel\Tests\Support\LateExtensionProvider;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * Late-bound registration traps (design §6): nothing resolves until a build starts, so extensions
 * contributed after boot — from a second provider, between two builds, or via config — all take
 * effect, and every build re-resolves the current registry.
 */
beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

function titleOf(): ?string
{
    // Build through DocumentBuilder so both programmatic (Docuccino::extend) and config-declared
    // extensions are exercised on the one path every command/viewer shares.
    return app(DocumentBuilder::class)->build('default', app(TypeEngine::class))->document->info['title'] ?? null;
}

/** Just the diagnostics about the configured extension list. */
function extensionDiagnostics(): array
{
    return array_values(array_filter(
        app(DocumentBuilder::class)->build('default', app(TypeEngine::class))->diagnostics,
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === 'config.extension-missing',
    ));
}

it('picks up an extension registered by a second provider booting after Docuccino', function (): void {
    // The app is already booted; registering the provider now runs its boot() immediately, which
    // is strictly "after" Docuccino's provider booted.
    app()->register(LateExtensionProvider::class);

    expect(titleOf())->toBe('LATE-BOUND');
});

it('re-resolves the registry on every build (build → extend → build)', function (): void {
    // First build: no extension registered yet.
    expect(titleOf())->not->toBe('LATE-BOUND');

    // Register between builds; the next build must reflect it.
    Docuccino::extend(new LateBoundMarker);

    expect(titleOf())->toBe('LATE-BOUND');
});

it('merges class-string extensions from config at build time', function (): void {
    config()->set('docuccino.extensions', [LateBoundMarker::class]);

    expect(titleOf())->toBe('LATE-BOUND');
});

it('says nothing about a config extension that resolves', function (): void {
    config()->set('docuccino.extensions', [LateBoundMarker::class]);

    expect(extensionDiagnostics())->toBe([]);
});

it('warns about a config extension no autoloadable class defines', function (): void {
    // `Foo::class` still evaluates to the string when the class is missing, so a typo'd namespace is a
    // build that quietly does less than the config asked for.
    config()->set('docuccino.extensions', ['App\\Docs\\InvoceTotalsExtension']);

    $diagnostics = extensionDiagnostics();

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->message)->toContain('App\\Docs\\InvoceTotalsExtension')
        ->and($diagnostics[0]->help)->toContain('config/docuccino.php');
});

it('warns about a config extensions entry that is no kind of extension', function (): void {
    config()->set('docuccino.extensions', [42]);

    expect(extensionDiagnostics()[0]->message)->toContain('holds a int');
});

it('reports a missing config extension on the CLI too', function (): void {
    config()->set('docuccino.extensions', ['App\\Docs\\Missing']);

    $this->artisan('docuccino:export', ['--out' => sys_get_temp_dir().'/docuccino-ext-'.uniqid().'.json'])
        ->expectsOutputToContain('config.extension-missing')
        ->assertSuccessful();
});
