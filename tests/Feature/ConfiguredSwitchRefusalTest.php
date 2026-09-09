<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Laravel\Config\ConfiguredFlags;
use Docuccino\Laravel\Pipeline\DocumentBuilder;
use Docuccino\Laravel\Pipeline\FragmentStore;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;

/**
 * The refusal, executed rather than asserted: this writes the configuration the reading is supposed
 * to refuse and confirms both halves of the promise — the switch's own DEFAULT is used, AND the build
 * says so, naming the key, what it holds and what was read instead.
 *
 * A degraded answer that says nothing is the failure this exists to prevent, and it is the one
 * `(bool) ($v ?? false)` used to produce: it read `'no'` as ON, in silence.
 */
function refusalDiagnostics(): array
{
    $diagnostics = app(DocumentBuilder::class)->build('default', app(TypeEngine::class))->diagnostics;

    return array_values(array_filter(
        $diagnostics,
        static fn (Diagnostic $diagnostic): bool => $diagnostic->code === ConfiguredFlags::CODE,
    ));
}

beforeEach(function (): void {
    app()->instance(TypeEngine::class, WorkbenchEngine::make());
});

it('refuses a document switch spelled no, uses the default, and says so', function (): void {
    // A default-ON switch: `!== false` used to read this as ON too, by accident, and the coercing
    // reader read it as ON on purpose. The answer here has to be the DEFAULT, reported.
    setBuild('documents.default.representation.errors.components', 'no');

    $found = refusalDiagnostics();

    expect($found)->toHaveCount(1)
        ->and($found[0]->severity)->toBe(Severity::Warning)
        // The code is spelled out, not read off the class: it is a name people put in
        // `diagnostics.accept`, so renaming it silently breaks their configuration.
        ->and($found[0]->code)->toBe('config.not-a-switch')
        ->and($found[0]->message)->toBe(
            'representation.errors.components is string rather than true or false, so it names no switch'
            .' — it is read as true, its default.',
        )
        ->and($found[0]->help)->toContain('Write true or false');
});

it('refuses an install switch spelled no, uses the default, and says so', function (): void {
    // The one a cast turned ON: `(bool) 'no'` is true, so this switch used to enable the fragment
    // cache for an author who had written it off.
    setBuild('cache.enabled', 'no');

    $found = refusalDiagnostics();

    expect($found)->toHaveCount(1)
        ->and($found[0]->message)->toContain('cache.enabled is string')
        ->and($found[0]->message)->toContain('read as false, its default')
        ->and(app(FragmentStore::class)->enabled)->toBeFalse();
});

it('refuses a lint switch spelled off, uses the rule default, and says so', function (): void {
    setBuild('lint.operation_ids.enabled', 'off');

    $found = refusalDiagnostics();

    expect($found)->toHaveCount(1)
        ->and($found[0]->message)->toContain('lint.operation_ids.enabled is string')
        ->and($found[0]->message)->toContain('read as true, its default');
});

it('refuses an integration switch and names the integration', function (): void {
    setBuild('documents.default.integrations.permission.enabled', 'yes');

    $found = refusalDiagnostics();

    expect($found)->toHaveCount(1)
        ->and($found[0]->message)->toContain('integrations.permission.enabled is string')
        // Opt-in, so the default is OFF — and `'yes'` does NOT opt in.
        ->and($found[0]->message)->toContain('read as false, its default');
});

it('says nothing at all about a configuration whose switches are switches', function (): void {
    setBuild('documents.default.representation.errors.components', false);
    setBuild('cache.enabled', true);
    config()->set('docuccino.enabled', true);
    setBuild('documents.default.integrations.permission.enabled', true);

    expect(refusalDiagnostics())->toBe([]);
});

it('says nothing about the switches the shipped configuration ships with', function (): void {
    // The firing population on a healthy install: zero. A diagnostic that fires on a config nobody
    // has touched is noise, and this is the assertion that keeps it from becoming that.
    expect(refusalDiagnostics())->toBe([]);
});

it('reports every refused switch, not just the first', function (): void {
    setBuild('cache.enabled', 'no');
    setBuild('lint.tags.enabled', 'no');
    setBuild('documents.default.routes.include_vendor', 'no');

    expect(refusalDiagnostics())->toHaveCount(3);
});

/**
 * A switch nothing reads is reported by the diagnostic that says nothing reads it, and not twice: an
 * always-on producer's `enabled` and a bag naming no integration each already have a message that
 * tells the author more than "that is not a switch" would.
 */
it('does not also refuse a switch that nothing reads', function (): void {
    setBuild('documents.default.integrations.validation.enabled', 'no');
    setBuild('documents.default.integrations.santcum.enabled', 'no');

    $codes = array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->code,
        app(DocumentBuilder::class)->build('default', app(TypeEngine::class))->diagnostics,
    );

    expect($codes)->toContain('config.enabled-ignored')
        ->toContain('config.unknown-integration')
        ->not->toContain(ConfiguredFlags::CODE);
});

/**
 * A refused switch is not an author saying `false`. The discoverability report tells an untaken opt-in
 * from a deliberate opt-out, and it must read a refusal as the former — the refusal diagnostic is what
 * says the value was unreadable, and one message saying so beats two disagreeing about what was meant.
 */
it('keeps a refused switch out of what counts as explicitly set', function (): void {
    setBuild('documents.default.integrations.spatie_data.enabled', 'no');

    $document = app(DocumentBuilder::class)->config('default');

    expect($document->integrationEnabledExplicit('spatie_data'))->toBeFalse()
        // And the integration is still ON, because that is its default.
        ->and($document->integrationEnabled('spatie_data', true))->toBeTrue();
});
