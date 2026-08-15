<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\TeamData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\TeamMemberData;
use Spatie\LaravelData\DataCollection;

/**
 * A Data class reached as a MAP's value. It has no dotted field path of its own — `members.*` is the only
 * child key Laravel's vocabulary offers, and a map's values are a schema rather than a path — so the
 * value schema has to be built request-side deliberately. Handing the type→schema chain a Data class
 * there instead would run the RESPONSE mapper: it keys by `#[MapOutputName]`, honours `#[Hidden]`, and
 * has never heard of `#[HiddenFromRequest]` — so a request body would publish exactly what that
 * attribute exists to withhold, and hoist the class into components from a request-only route.
 */

/** The `members` property's request schema, through the sequence the extension runs. */
function memberMapRequestSchema(DType $membersType): array
{
    $engine = new StubTypeEngine(classes: [
        TeamData::class => new ClassMetadata(TeamData::class, [new PropertyMetadata('members', $membersType)]),
        TeamMemberData::class => new ClassMetadata(TeamMemberData::class, [
            new PropertyMetadata('email', ScalarT::string()),
            new PropertyMetadata('internal_risk_score', ScalarT::string()),
            new PropertyMetadata('joining_token', ScalarT::string()),
        ]),
    ]);

    $converter = new SchemaConverter(
        [new DataSchema, ...DefaultTypeMappers::all()],
        $engine,
        $components = new ComponentRegistry,
        new RepresentationPolicy,
    );
    $validation = new DefaultValidationRulesToSchema(ValidationIntegration::transformers());

    $rules = (new DataValidationRules)->build(
        TeamData::class,
        new ClassMetadata(TeamData::class, [new PropertyMetadata('members', $membersType)]),
        $engine,
        null,
        $converter,
        $validation,
    );

    $schema = $validation->convert(
        (new RuleOrdering)->order((new RuleSetNormalizer)->normalize($rules)),
        $converter,
    )->schema;

    return [$schema['properties']['members'], $components->schemas()];
}

it('builds a map value Data class from its own REQUEST fields', function (): void {
    [$members, $components] = memberMapRequestSchema(new MapT(ScalarT::string(), new ClassT(TeamMemberData::class)));

    expect($members)->toBe([
        'type' => 'object',
        'additionalProperties' => [
            'type' => 'object',
            // #[MapInputName('email_address')] is the key a request sends; #[MapOutputName('email')] is
            // the response's, and taking the wrong one would document a body nothing can send.
            'properties' => [
                'email_address' => ['type' => 'string'],
                // #[Hidden] is output-only: hidden from the response, still a sendable request field —
                // deliberately, so the leakage lint can surface it.
                'joining_token' => ['type' => 'string'],
            ],
            'required' => ['email_address', 'joining_token'],
        ],
    ]);

    // Nothing hoisted: a request-only route must not publish the response component for the value class.
    expect($components)->toBe([]);
});

it('never carries a #[HiddenFromRequest] property into a map value', function (): void {
    [$members] = memberMapRequestSchema(new MapT(ScalarT::string(), new ClassT(TeamMemberData::class)));

    expect(json_encode($members))->not->toContain('internal_risk_score');
});

it('says nothing rather than the response shape wherever a Data class hides in the value', function (DType $value): void {
    // There is no request-side builder for a Data class reached through another container, and
    // converting it would run the response mapper — so the values go unconstrained wherever it is
    // buried. Vague and true beats precise and wrong, and it stays out of components either way. Every
    // container kind the search descends is here.
    [$members, $components] = memberMapRequestSchema(new MapT(ScalarT::string(), $value));

    expect($members)->toBe(['type' => 'object', 'additionalProperties' => []])
        ->and($components)->toBe([]);
})->with([
    'a list of Data' => [new ListT(new ClassT(TeamMemberData::class))],
    'a map of Data' => [new MapT(ScalarT::string(), new ClassT(TeamMemberData::class))],
    'a union with a Data member' => [UnionT::of([ScalarT::string(), new ClassT(TeamMemberData::class)])],
    'a shape with a Data member' => [new ArrayShapeT([new ArrayShapeField('lead', new ClassT(TeamMemberData::class))])],
    'a Data class as a generic argument' => [new ClassT('App\\Box', [new ClassT(TeamMemberData::class)])],
    'a spatie collectable of Data' => [new ClassT(DataCollection::class, [new ClassT(TeamMemberData::class)])],
]);

it('still converts a map value that names no Data class at all', function (DType $value, array $expected): void {
    // The unknown-entry half: nothing about the Data guard changes a plain value type, at any depth.
    [$members] = memberMapRequestSchema(new MapT(ScalarT::string(), $value));

    expect($members)->toBe(['type' => 'object', 'additionalProperties' => $expected]);
})->with([
    'a scalar' => [ScalarT::int(), ['type' => 'integer']],
    'a list' => [new ListT(ScalarT::int()), ['type' => 'array', 'items' => ['type' => 'integer']]],
    'a shape' => [
        new ArrayShapeT([new ArrayShapeField('size', ScalarT::int())]),
        ['type' => 'object', 'properties' => ['size' => ['type' => 'integer']], 'required' => ['size']],
    ],
]);

it('breaks a cycle a map value would otherwise recurse into forever', function (): void {
    // `array<string, TeamData>` on TeamData itself: the class is already being visited, so the value
    // takes the unconstrained branch rather than descending again.
    [$members] = memberMapRequestSchema(new MapT(ScalarT::string(), new ClassT(TeamData::class)));

    expect($members)->toBe(['type' => 'object', 'additionalProperties' => []]);
});

it('degrades a map value to the bare array rule when no rule chain is available', function (): void {
    // `propertyFieldKeys()` runs with neither chain — it only needs the KEYS — so the map contributes no
    // `additional_properties` rule at all there, and certainly no response shape.
    $engine = new StubTypeEngine(classes: [
        TeamMemberData::class => new ClassMetadata(TeamMemberData::class, [new PropertyMetadata('email', ScalarT::string())]),
    ]);
    $metadata = new ClassMetadata(TeamData::class, [
        new PropertyMetadata('members', new MapT(ScalarT::string(), new ClassT(TeamMemberData::class))),
    ]);

    expect((new DataValidationRules)->propertyFieldKeys(TeamData::class, $metadata, $engine))->toBe(['members']);
});
