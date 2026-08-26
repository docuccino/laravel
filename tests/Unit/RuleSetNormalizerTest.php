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

it('replaces the array rule with `object` on a field a named child proves is an object', function (string $arrayRule): void {
    // Dropping the word left the field with no type word at all, so every type-aware rule after it —
    // `min`/`max`/`size` — read an untyped field and published string-length bounds on an object.
    expect(normalizedNames([
        'metadata' => ['nullable', $arrayRule, 'max'],
        'metadata.retention' => ['string'],
    ]))->toBe([
        'metadata' => ['nullable', 'object', 'max'],
        'metadata.retention' => ['string'],
    ]);
})->with(['array', 'list']);

it('leaves one object word on a field that stated the array word twice', function (): void {
    // A real pair: recovery synthesises `array` and an override restates `list`. Two `object` words
    // would be the same rule applied twice.
    expect(normalizedNames([
        'metadata' => ['array', 'list'],
        'metadata.retention' => ['string'],
    ]))->toBe([
        'metadata' => ['object'],
        'metadata.retention' => ['string'],
    ]);
});

it('reads a purely numeric field key as the path it is', function (): void {
    // Such a key is an INT in a PHP array, and every path read here wants a string — uncast, the
    // normalizer raises a TypeError under strict_types rather than answering at all.
    expect(normalizedNames(['0' => ['array'], '0.mode' => ['string']]))
        ->toBe(['0' => ['object'], '0.mode' => ['string']])
        ->and(normalizedNames(['0' => ['prohibited'], '0.mode' => ['string'], 'name' => ['string']]))
        ->toBe(['name' => ['string']]);
});

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

it('bounds an object a named child proves by its keys, not by its length', function (): void {
    // `max:2` on an array-or-object value counts elements in Laravel, so `maxLength` here is a bound
    // no validator applies. The object word the normalizer leaves behind is what the size rule reads.
    $set = new RuleSet([
        'metadata' => [ValidationRule::of('array'), ValidationRule::of('max', ['2'])],
        'metadata.mode' => [ValidationRule::of('string')],
    ]);

    expect(validationSchema($set, schemaConverter())['properties']['metadata'])->toBe([
        'type' => 'object',
        'maxProperties' => 2,
        'properties' => ['mode' => ['type' => 'string']],
    ]);
});
