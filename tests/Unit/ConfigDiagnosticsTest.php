<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Contracts\TagMapper;
use Docuccino\Laravel\Config\ConfiguredFlags;
use Docuccino\Laravel\Integrations\QueryBuilder\QueryBuilderParameters;
use Docuccino\Laravel\Registry\ConfigDiagnostics;
use Docuccino\Laravel\Registry\IntegrationToggles;
use Docuccino\Laravel\Tests\Fixtures\Tags\StatefulTagMapper;

/**
 * The config-shape info diagnostics (design §9, B7): the silent no-ops the config surface used to
 * swallow — an `enabled` switch on an always-on producer, an `integrations` key nothing reads, an
 * unknown tags.default_strategy, an `error_responses` value naming no strategy, and a dropped
 * tags.definitions `parent` — are surfaced as diagnostics so a misconfiguration is discoverable.
 */
function configDoc(array $integrations = [], array $tags = [], array $representation = [], array $raw = []): DocumentConfig
{
    if ($integrations !== []) {
        $raw['integrations'] = $integrations;
    }
    if ($representation !== []) {
        $raw['representation'] = $representation;
    }
    // The raw bag is the whole of what the document was configured with, so a modelled section is in
    // BOTH — which is what the config factory builds and what the readers that scan the raw bag see.
    if ($tags !== []) {
        $raw['tags'] = $tags;
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
        'validation', 'form_request', 'framework_errors', 'inferred_handler',
    ]),
]);

/**
 * `error_responses` reports under the one code the whole keyword family reports under, and no longer
 * under a name of its own. The fact is not specific to this key — a value outside a closed set, so the
 * documented default was used — and the remedy is not either: write one of the values the message
 * lists. A code per setting made an author who wanted to accept the family list every one of them, and
 * had this key warning where `tags.default_strategy` next to it merely informed, for no reason anyone
 * could state.
 */
it('warns, and names what was built instead, for an error_responses value that is not one of the two', function (mixed $configured, string $named): void {
    // The key decides what EVERY error response in the document says, so a value nothing recognises is
    // reported rather than quietly read as one of them — including a shape (an array, say) that once meant
    // something here, where the silent reading would be a document with no error responses at all.
    $diagnostics = ConfigDiagnostics::for(configDoc(raw: ['error_responses' => $configured]));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->code)->toBe('config.unknown-value')
        ->and($diagnostics[0]->message)->toContain($named)
        ->and($diagnostics[0]->message)->toContain('read as "default", its default')
        ->and($diagnostics[0]->help)->toBe('Write one of: "default", "none".');
})->with([
    'a strategy name nothing recognises' => ['problem-details', 'the text "problem-details"'],
    'a misspelling' => ['defualt', 'the text "defualt"'],
    'an array where a strategy name belongs' => [['preset' => 'problem-details'], 'a map'],
    'a boolean' => [false, 'the boolean false'],
    // The key written with nothing after the colon. It is a PRESENT key, so it reads as `default` like
    // every other unrecognised value — only deleting the key gets you `none`.
    'a key with nothing after the colon' => [null, 'empty'],
]);

it('says nothing about the two error_responses values there are, or about a document that sets neither', function (): void {
    // The third case is ABSENCE, which is not a misconfiguration: it is how a document asks for no error
    // responses at all, and the row above proves that a key present and holding null is a different thing.
    expect(ConfigDiagnostics::for(configDoc(raw: ['error_responses' => 'default'])))->toBe([])
        ->and(ConfigDiagnostics::for(configDoc(raw: ['error_responses' => 'none'])))->toBe([])
        ->and(ConfigDiagnostics::for(configDoc()))->toBe([]);
});

/**
 * And a WARNING where it used to inform, for the reason its neighbours warn: the build did not ignore a
 * switch nobody reads, it discarded an instruction somebody wrote — every operation with no `#[Group]`
 * is tagged by this, so a value read as something else regroups the whole document. What the severity
 * is a function of is the KIND of defect, not the setting's blast radius; that is what
 * {@see ConfiguredFlags} settled for the switches, where the master switch
 * and `viewer.cdn` both warn.
 */
it('warns for an unknown tags.default_strategy value', function (): void {
    $diagnostics = ConfigDiagnostics::for(configDoc(tags: ['default_strategy' => 'wibble']));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->code)->toBe('config.unknown-value')
        ->and($diagnostics[0]->message)->toBe(
            'tags.default_strategy is the text "wibble", which is none of the values it takes'
            .' — it is read as "controller", its default.',
        );
});

it('does not flag a known tags.default_strategy value', function (string $strategy): void {
    expect(ConfigDiagnostics::for(configDoc(tags: ['default_strategy' => $strategy])))->toBe([]);
})->with(['controller', 'none']);

/*
 * `tags.mapper` names a collaborator, and a name is four things it can be: no name at all, a name
 * nothing loads, a name that loads and is no mapper, and a mapper the container could not build. The
 * document is truthful in all four — its tags are the ones the code wrote — so each is a WARNING saying
 * the key did not take, and the message says which of the four it was.
 */

it('warns, and says which of the four states it is in, for a tags.mapper that produced no mapper', function (mixed $configured, string $expected): void {
    // No mapper beside a name in the bag is the whole condition, which is what a resolved document with
    // an unusable `tags.mapper` looks like ({@see ConfiguredTagMapper}).
    $diagnostics = ConfigDiagnostics::for(configDoc(tags: ['mapper' => $configured]));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->code)->toBe('config.tag-mapper-unusable')
        ->and($diagnostics[0]->message)->toContain($expected)
        ->and($diagnostics[0]->message)->toContain('documents.default.tags.mapper');
})->with([
    'not a string at all' => [123, 'is int rather than the name of a class'],
    'an empty string' => ['', "is '' rather than the name of a class"],
    'whitespace' => ['   ', 'rather than the name of a class'],
    'a name nothing loads' => ['Not\\A\\Real\\Mapper', 'is neither an autoloadable class nor a name the container has bound'],
    'a class that is no mapper' => [stdClass::class, 'does not implement '.TagMapper::class],
    'a mapper the container cannot build' => [StatefulTagMapper::class, 'the container could not build'],
]);

it('says nothing about a document that names no tags.mapper, or one whose mapper resolved', function (): void {
    // The other half: a bag with no `mapper` key is silent, and so is one whose mapper is right there.
    expect(ConfigDiagnostics::for(configDoc(tags: ['map' => ['a' => 'b']])))->toBe([])
        ->and(ConfigDiagnostics::for(new DocumentConfig(
            'default',
            [],
            tags: ['mapper' => StatefulTagMapper::class],
            tagMapper: new StatefulTagMapper('V1'),
        )))->toBe([]);
});

it('emits an info diagnostic for a tag parent that no definition declares', function (): void {
    $diagnostics = ConfigDiagnostics::for(configDoc(tags: ['definitions' => [
        ['name' => 'Invoices', 'parent' => 'Billing'],
    ]]));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Info)
        ->and($diagnostics[0]->code)->toBe('config.unknown-tag-parent')
        ->and($diagnostics[0]->message)->toContain("'Invoices'")->toContain("'Billing'");
});

it('emits an info diagnostic for a tag defined twice by definitions that agree', function (): void {
    $diagnostics = ConfigDiagnostics::for(configDoc(tags: ['definitions' => [
        ['name' => 'Billing', 'summary' => 'Billing'],
        ['name' => 'Billing', 'description' => 'Everything money.'],
    ]]));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Info)
        ->and($diagnostics[0]->code)->toBe('config.duplicate-tag-definition')
        ->and($diagnostics[0]->message)->toContain("'Billing'")->toContain('2 times');
});

it('warns, and names the members, when the definitions of one tag disagree', function (): void {
    // Warning rather than Info because this one loses something the author wrote: nothing published
    // says what the summary or the parent is.
    $diagnostics = ConfigDiagnostics::for(configDoc(tags: ['definitions' => [
        ['name' => 'Billing', 'summary' => 'Money in', 'parent' => 'Ledger'],
        ['name' => 'Billing', 'summary' => 'Money out', 'parent' => 'Accounts'],
        ['name' => 'Ledger'],
        ['name' => 'Accounts'],
    ]]));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->code)->toBe('config.duplicate-tag-definition')
        ->and($diagnostics[0]->message)->toContain("'Billing'")->toContain('summary, parent');
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
            'Filter kinds are: default, partial, exact, beginsWith, endsWith, beginsWithStrict, endsWithStrict, scope, callback, custom, operator, groupOr, groupAnd, trashed, belongsTo.',
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
