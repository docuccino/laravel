<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Registry\ConfigDiagnostics;

/**
 * The config-shape info diagnostics (design §9, B7): the two silent no-ops the config surface used
 * to swallow — an `enabled` switch on an always-on producer, and an unknown tags.default_strategy —
 * are surfaced as info diagnostics so a misconfiguration is discoverable.
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
