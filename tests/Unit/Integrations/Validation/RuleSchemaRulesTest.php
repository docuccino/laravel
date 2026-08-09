<?php

declare(strict_types=1);

use Docuccino\Attributes\RuleSchema;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Validation\RuleSchemaRules;

/**
 * The `#[RuleSchema]` → rule-vocabulary map: every field gets a row, so a field added to the attribute
 * without a mapping fails here. Rules are rendered as `name:params` for readability.
 *
 * @return list<string>
 */
function renderedRuleSchema(RuleSchema $schema): array
{
    return array_map(
        static fn (ValidationRule $rule): string => $rule->parameters === []
            ? $rule->name
            : $rule->name.':'.implode(',', $rule->parameters),
        RuleSchemaRules::of($schema),
    );
}

it('maps every RuleSchema field onto its rule', function (RuleSchema $schema, array $expected): void {
    expect(renderedRuleSchema($schema))->toBe($expected);
})->with([
    'type: string' => [new RuleSchema(type: 'string'), ['string']],
    'type: integer' => [new RuleSchema(type: 'integer'), ['integer']],
    'type: number aliases to numeric' => [new RuleSchema(type: 'number'), ['numeric']],
    'type: boolean' => [new RuleSchema(type: 'boolean'), ['boolean']],
    'type: array' => [new RuleSchema(type: 'array'), ['array']],
    // Any other type is a rule name, so the format-bearing type rules work…
    'type: a format-bearing rule name' => [new RuleSchema(type: 'email'), ['email']],
    // …and a typo reaches the chain as an unknown rule, which diagnoses it.
    'type: unknown' => [new RuleSchema(type: 'strng'), ['strng']],
    'type: blank is dropped' => [new RuleSchema(type: ' '), []],
    'enum' => [new RuleSchema(enum: ['a', 'b']), ['in:a,b']],
    'enum: numeric values stringify' => [new RuleSchema(enum: [1, 2]), ['in:1,2']],
    'enum: empty is dropped' => [new RuleSchema(enum: []), []],
    'pattern is delimited for the regex rule' => [new RuleSchema(pattern: '^GB[0-9]+$'), ['regex:/^GB[0-9]+$/']],
    'pattern: already delimited is left alone' => [new RuleSchema(pattern: '/^GB[0-9]+$/'), ['regex:/^GB[0-9]+$/']],
    'min' => [new RuleSchema(min: 3), ['min:3']],
    'max' => [new RuleSchema(max: 10), ['max:10']],
    'min: float keeps its fraction' => [new RuleSchema(min: 1.5), ['min:1.5']],
    'format' => [new RuleSchema(format: 'iban'), ['format:iban']],
    'description' => [new RuleSchema(description: 'A, with a comma.'), ['description:A, with a comma.']],
    'example: string' => [new RuleSchema(example: 'GB12'), ['example:GB12']],
    'example: int' => [new RuleSchema(example: 42), ['example:42']],
    'example: bool' => [new RuleSchema(example: true), ['example:true']],
    'example: float' => [new RuleSchema(example: 1.25), ['example:1.25']],
    'nothing declared' => [new RuleSchema, []],
]);

it('emits the combined rules in effect order', function (): void {
    $schema = new RuleSchema(
        type: 'string',
        format: 'bank-reference',
        pattern: '[A-Z]{2}',
        enum: ['GB12', 'GB34'],
        min: 4,
        max: 4,
        description: 'A bank reference.',
        example: 'GB12',
    );

    expect(renderedRuleSchema($schema))->toBe([
        'string',
        'in:GB12,GB34',
        'regex:/[A-Z]{2}/',
        'min:4',
        'max:4',
        'format:bank-reference',
        'description:A bank reference.',
        'example:GB12',
    ]);
});

it('keeps a description parameter whole rather than splitting it on commas', function (): void {
    // The mapper never round-trips through rule-string parsing, so prose survives intact.
    $rules = RuleSchemaRules::of(new RuleSchema(description: 'Two letters, then six digits.'));

    expect($rules[0]->parameters)->toBe(['Two letters, then six digits.']);
});
