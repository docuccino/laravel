<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Registry\ConfigDiagnostics;
use Docuccino\Laravel\Registry\IntegrationToggles;

/**
 * The config-shape info diagnostics (design §9, B7): the silent no-ops the config surface used to
 * swallow — an `enabled` switch on an always-on producer, an `integrations` key nothing reads, an
 * unknown tags.default_strategy, and a
 * dropped tags.definitions `parent` — are surfaced as info diagnostics so a misconfiguration is
 * discoverable.
 */
function configDoc(array $integrations = [], array $tags = []): DocumentConfig
{
    $raw = [];
    if ($integrations !== []) {
        $raw['integrations'] = $integrations;
    }

    return new DocumentConfig('default', [], tags: $tags, raw: $raw);
}

it('emits an info diagnostic when an always-on producer carries an enabled switch', function (string $key): void {
    $diagnostics = ConfigDiagnostics::for(configDoc([$key => ['enabled' => false]]));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Info)
        ->and($diagnostics[0]->code)->toBe('config.enabled-ignored')
        ->and($diagnostics[0]->message)->toContain('integrations.'.$key.'.enabled');
})->with([
    'validation' => ['validation'],
    'form_request' => ['form_request'],
    'framework_errors' => ['framework_errors'],
    'problem_details' => ['problem_details'],
    'inferred_handler' => ['inferred_handler'],
]);

it('does not flag a toggleable integration carrying an enabled switch', function (): void {
    // spatie_data IS toggleable — its enabled switch is honoured, so no config diagnostic.
    expect(ConfigDiagnostics::for(configDoc(['spatie_data' => ['enabled' => false]])))->toBe([]);
});

it('does not flag an always-on producer with no enabled switch present', function (): void {
    expect(ConfigDiagnostics::for(configDoc(['validation' => ['some' => 'other']])))->toBe([]);
});

it('emits an info diagnostic for an integrations key nothing reads', function (): void {
    $diagnostics = ConfigDiagnostics::for(configDoc(['santcum' => ['modes' => ['token']]]));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Info)
        ->and($diagnostics[0]->code)->toBe('config.unknown-integration')
        ->and($diagnostics[0]->message)->toContain('integrations.santcum')
        // A near miss is a typo, so the help names the one key they meant.
        ->and($diagnostics[0]->help)->toBe('Did you mean integrations.sanctum?');
});

it('lists the valid keys when the unknown one resembles none of them', function (): void {
    $diagnostics = ConfigDiagnostics::for(configDoc(['wibblesprocket' => ['enabled' => true]]));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->help)->toContain('Valid keys are')
        ->toContain('sanctum')
        ->toContain('form_request');
});

it('flags every unknown key, in config order', function (): void {
    $diagnostics = ConfigDiagnostics::for(configDoc(['zzz' => [], 'aaa' => []]));

    expect(array_map(static fn ($d): string => $d->message, $diagnostics))->toBe([
        'integrations.zzz names no integration — nothing reads that bag, so its settings do nothing.',
        'integrations.aaa names no integration — nothing reads that bag, so its settings do nothing.',
    ]);
});

it('does not flag a key some integration actually reads', function (string $key): void {
    expect(ConfigDiagnostics::for(configDoc([$key => []])))->toBe([]);
})->with([
    ...array_map(static fn (string $key): array => [$key], array_keys(IntegrationToggles::descriptors())),
    ...array_map(static fn (string $key): array => [$key], [
        'validation', 'form_request', 'framework_errors', 'problem_details', 'inferred_handler',
    ]),
]);

it('emits an info diagnostic for an unknown tags.default_strategy value', function (): void {
    $diagnostics = ConfigDiagnostics::for(configDoc(tags: ['default_strategy' => 'wibble']));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Info)
        ->and($diagnostics[0]->code)->toBe('config.unknown-tag-strategy')
        ->and($diagnostics[0]->message)->toContain('wibble');
});

it('does not flag a known tags.default_strategy value', function (string $strategy): void {
    expect(ConfigDiagnostics::for(configDoc(tags: ['default_strategy' => $strategy])))->toBe([]);
})->with(['controller', 'none']);

it('emits an info diagnostic for a tag parent that no definition declares', function (): void {
    $diagnostics = ConfigDiagnostics::for(configDoc(tags: ['definitions' => [
        ['name' => 'Invoices', 'parent' => 'Billing'],
    ]]));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Info)
        ->and($diagnostics[0]->code)->toBe('config.unknown-tag-parent')
        ->and($diagnostics[0]->message)->toContain("'Invoices'")->toContain("'Billing'");
});

it('emits an info diagnostic for a tag parent link that closes a cycle', function (): void {
    $diagnostics = ConfigDiagnostics::for(configDoc(tags: ['definitions' => [
        ['name' => 'Invoices', 'parent' => 'Billing'],
        ['name' => 'Billing', 'parent' => 'Invoices'],
    ]]));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Info)
        ->and($diagnostics[0]->code)->toBe('config.tag-parent-cycle')
        ->and($diagnostics[0]->message)->toContain("'Invoices'")->toContain("'Billing'");
});

it('does not flag a tag hierarchy whose parents all resolve', function (): void {
    expect(ConfigDiagnostics::for(configDoc(tags: ['definitions' => [
        ['name' => 'Billing', 'kind' => 'nav'],
        ['name' => 'Invoices', 'parent' => 'Billing'],
        ['name' => 'Refunds', 'parent' => 'Invoices'],
    ]])))->toBe([]);
});
