<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;
use Docuccino\Laravel\Registry\ConfigDiagnostics;
use Docuccino\Laravel\Registry\IntegrationToggles;

/**
 * The config-shape info diagnostics (design §9, B7): the silent no-ops the config surface used to
 * swallow — an `enabled` switch on an always-on producer, an `integrations` key nothing reads, an
 * unknown tags.default_strategy, and a
 * dropped tags.definitions `parent` — are surfaced as info diagnostics so a misconfiguration is
 * discoverable.
 */
function configDoc(array $integrations = [], array $tags = [], array $representation = []): DocumentConfig
{
    $raw = [];
    if ($integrations !== []) {
        $raw['integrations'] = $integrations;
    }
    if ($representation !== []) {
        $raw['representation'] = $representation;
    }

    return new DocumentConfig('default', [], tags: $tags, representation: $representation, raw: $raw);
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

/**
 * A filter-description override keyed on a kind nothing has is the config equivalent of a scan that
 * matches nothing: the document looks configured and the prose never moves. Reported once per build,
 * beside the other config-shape no-ops, rather than once per Query Builder route.
 */
it('emits an info diagnostic for a filter_descriptions key naming no filter kind', function (): void {
    $diagnostics = ConfigDiagnostics::for(configDoc([
        'query_builder' => ['filter_descriptions' => ['exactt' => 'Matches `%field%` exactly.']],
    ]));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Info)
        ->and($diagnostics[0]->code)->toBe('config.unknown-filter-kind')
        ->and($diagnostics[0]->message)->toBe(
            "integrations.query_builder.filter_descriptions names filter kind 'exactt', which no Query Builder filter has — the sentence under it is never used.",
        )
        // The help lists the kinds that exist, read off the table itself.
        ->and($diagnostics[0]->help)->toBe(
            'Filter kinds are: default, partial, exact, beginsWithStrict, endsWithStrict, scope, callback, custom, operator, trashed, belongsTo.',
        );
});

it('flags every unknown filter kind, in config order, and no known one', function (): void {
    $diagnostics = ConfigDiagnostics::for(configDoc([
        'query_builder' => ['filter_descriptions' => [
            'zzz' => 'One.',
            'exact' => 'Matches `%field%` exactly.',
            'aaa' => 'Two.',
        ]],
    ]));

    expect(array_map(static fn ($d): string => $d->code, $diagnostics))->toBe([
        'config.unknown-filter-kind',
        'config.unknown-filter-kind',
    ])
        ->and($diagnostics[0]->message)->toContain("'zzz'")
        ->and($diagnostics[1]->message)->toContain("'aaa'");
});

it('does not flag a filter_descriptions key that names a real filter kind', function (string $kind): void {
    expect(ConfigDiagnostics::for(configDoc([
        'query_builder' => ['filter_descriptions' => [$kind => 'A sentence about `%field%`.']],
    ])))->toBe([]);
})->with(array_map(
    static fn (string $kind): array => [$kind],
    QueryBuilderParameters::filterKinds(),
));

/**
 * `format` only ever constrains a string, so a non-string sample could not be published by anything —
 * the policy drops it, and this is what stops the drop being silent. A sample a particular FIELD's rules
 * reject is the build's to report, under the same code, because only the build knows the field.
 */
it('emits a warning for a format sample that is not a string', function (mixed $sample, string $type): void {
    $diagnostics = ConfigDiagnostics::for(configDoc(representation: [
        'examples' => ['formats' => ['email' => $sample]],
    ]));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->code)->toBe('config.format-sample-rejected')
        ->and($diagnostics[0]->message)->toBe(sprintf(
            'The example configured for format "email" is %s rather than a string, and `format` only ever constrains a string, so nothing can publish it — the format is illustrated as if it had never been configured.',
            $type,
        ))
        ->and($diagnostics[0]->help)->toBe('Set representation.examples.formats.email to a string, or drop the key.');
})->with([
    'an int' => [42, 'int'],
    'a bool' => [true, 'bool'],
    'null' => [null, 'null'],
    'a nested array' => [['jane@example.com'], 'array'],
]);

it('says nothing about a representation.examples.formats map of strings', function (mixed $formats): void {
    expect(ConfigDiagnostics::for(configDoc(representation: ['examples' => ['formats' => $formats]])))->toBe([]);
})->with([
    'an empty map' => [[]],
    'one format' => [['email' => 'jane@example.com']],
    'several' => [['email' => 'jane@example.com', 'hostname' => 'api.example.net']],
    // A format nothing uses is not an error — examples are demand-driven.
    'a format no schema carries' => [['iban' => 'GB33BUKB20201555555555']],
    'a non-array where the map should be' => ['jane@example.com'],
]);

it('says nothing about a representation bag with no examples in it', function (array $representation): void {
    expect(ConfigDiagnostics::for(configDoc(representation: $representation)))->toBe([]);
})->with([
    'only the other keywords' => [['operation_id' => 'controller-method', 'nullable' => 'anyof']],
    'an empty examples bag' => [['examples' => []]],
    'a non-array examples bag' => [['examples' => 'nonsense']],
]);

it('says nothing about a query_builder bag with no filter_descriptions in it', function (mixed $bag): void {
    expect(ConfigDiagnostics::for(configDoc(['query_builder' => $bag])))->toBe([]);
})->with([
    'an empty bag' => [[]],
    'only the other options' => [['enabled' => true, 'pagination_terminals' => ['paginateList']]],
    'an empty description map' => [['filter_descriptions' => []]],
    'a non-array where the map should be' => [['filter_descriptions' => 'Exact match.']],
]);
