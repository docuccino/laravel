<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;

/**
 * The two cross-field facts a per-field rule transformer cannot see: a field the API prohibits outright,
 * and a field whose dotted child key proves it is an object rather than the array its own rule claims.
 * Both are settled on the rule set, before the chain runs.
 */

/**
 * @param  array<string, list<string>>  $fields  field → rule names (parameters aren't what's under test)
 * @return array<string, list<string>>
 */
function normalizedNames(array $fields): array
{
    $set = new RuleSet(array_map(
        static fn (array $names): array => array_map(static fn (string $name): ValidationRule => ValidationRule::of($name), $names),
        $fields,
    ));

    return array_map(
        static fn (array $rules): array => array_map(static fn (ValidationRule $rule): string => $rule->name, $rules),
        (new RuleSetNormalizer)->normalize($set)->fields,
    );
}

it('drops an unconditionally prohibited field and everything under it', function (): void {
    expect(normalizedNames([
        'name' => ['string'],
        'label' => ['prohibited'],
        'label.locale' => ['string'],
        'label.*' => ['string'],
        // A field merely PREFIXED by the dropped name is a different field and survives.
        'labelling' => ['string'],
    ]))->toBe([
        'name' => ['string'],
        'labelling' => ['string'],
    ]);
});

it('keeps a conditionally prohibited field, which is legitimately sendable', function (string $rule): void {
    expect(normalizedNames(['legacy' => ['string', $rule]]))->toBe(['legacy' => ['string', $rule]]);
})->with(['prohibited_if', 'prohibited_unless', 'prohibits']);

it('drops the array rule from a field a named child proves is an object', function (string $arrayRule): void {
    expect(normalizedNames([
        'metadata' => ['nullable', $arrayRule],
        'metadata.retention' => ['string'],
    ]))->toBe([
        'metadata' => ['nullable'],
        'metadata.retention' => ['string'],
    ]);
})->with(['array', 'list']);

it('keeps the array rule when the only child is a wildcard, which IS an array', function (): void {
    expect(normalizedNames([
        'tags' => ['array'],
        'tags.*' => ['string'],
        'items' => ['array'],
        'items.*.id' => ['integer'],
    ]))->toBe([
        'tags' => ['array'],
        'tags.*' => ['string'],
        'items' => ['array'],
        'items.*.id' => ['integer'],
    ]);
});

it('leaves an ordinary rule set untouched', function (): void {
    $fields = ['name' => ['required', 'string'], 'age' => ['integer']];

    expect(normalizedNames($fields))->toBe($fields);
});

it('turns the clashing array-plus-child pair into a coherent object schema', function (): void {
    // The end-to-end point: `{"type": "array", "properties": …}` is not a schema any JSON object
    // validates against, and it is what the un-normalised set emits.
    $set = new RuleSet([
        'metadata' => [ValidationRule::of('array')],
        'metadata.mode' => [ValidationRule::of('string')],
    ]);
    $context = schemaConverter();
    $clashing = validationSchema($set, $context, normalize: false);
    $resolved = validationSchema($set, $context);

    expect($clashing['properties']['metadata']['type'])->toBe('array')
        ->and($resolved['properties']['metadata'])->toBe([
            'type' => 'object',
            'properties' => ['mode' => ['type' => 'string']],
        ]);
});
