<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;

/**
 * The cross-field facts a per-field rule transformer cannot see: a field the API prohibits outright, and
 * what a field's CHILD keys say about the container its own `array` word cannot decide — a named key
 * proves an object, no key at all proves nothing and leaves both open. All settled on the rule set,
 * before the chain runs.
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

/**
 * The container matrix at the rule-name level: what each combination of child keys and container words
 * leaves the field stating. `array` is the one word Laravel has for both containers, so every row is the
 * same question — what else in the rule set answers it.
 */
it('decides a container from the child keys, and says so when they cannot', function (array $fields, array $expected): void {
    expect(normalizedNames($fields))->toBe($expected);
})->with([
    // Nothing under the field: a JSON array and a JSON object both pass these rules.
    'bare array' => [
        ['meta' => ['sometimes', 'nullable', 'array']],
        ['meta' => ['sometimes', 'nullable', 'array_or_object']],
    ],
    // `*` constrains every value whatever the keys are, so it decides nothing about key type — but it IS
    // a statement about what is inside, which is what the widening is for the absence of.
    'array with a `*` child' => [
        ['meta' => ['array'], 'meta.*' => ['uuid']],
        ['meta' => ['array'], 'meta.*' => ['uuid']],
    ],
    'array with named children' => [
        ['meta' => ['array'], 'meta.mode' => ['string']],
        ['meta' => ['object'], 'meta.mode' => ['string']],
    ],
    'array with both' => [
        ['meta' => ['array'], 'meta.*' => ['uuid'], 'meta.mode' => ['string']],
        ['meta' => ['object'], 'meta.*' => ['uuid'], 'meta.mode' => ['string']],
    ],
    // A dotted parent is decided by its own children, and the leaf by its own — one undecided field
    // beside a decided sibling is exactly the shape a partial-update body arrives in.
    'nested dotted parents' => [
        ['meta' => ['array'], 'meta.overrides' => ['array'], 'meta.options' => ['array'], 'meta.options.*' => ['uuid']],
        ['meta' => ['object'], 'meta.overrides' => ['array_or_object'], 'meta.options' => ['array'], 'meta.options.*' => ['uuid']],
    ],
    // A word that settles the container on its own leaves nothing open, whether the author wrote it
    // (`list`) or a recovery synthesised it from a type.
    'array beside `list`' => [['meta' => ['array', 'list']], ['meta' => ['array', 'list']]],
    'array beside `object`' => [['meta' => ['array', 'object']], ['meta' => ['array', 'object']]],
    'array beside `additional_properties`' => [
        ['meta' => ['array', 'additional_properties']],
        ['meta' => ['array', 'additional_properties']],
    ],
    // A field merely PREFIXED by another's name is a different field, not a child of it — the same
    // distinction the prohibited pass draws, and getting it wrong would read `meta` as decided.
    'a longer field name is not a child' => [
        ['meta' => ['array'], 'metadata.mode' => ['string']],
        ['meta' => ['array_or_object'], 'metadata.mode' => ['string']],
    ],
    // No container word at all is a different field entirely; nothing here is a container question.
    'no array word' => [['name' => ['required', 'string']], ['name' => ['required', 'string']]],
]);

it('publishes both containers, and bounds both, for a field the rules leave open', function (): void {
    // The reported defect: `type: array` is not vague about a free-form map, it is wrong about one, and
    // a contract check on the endpoint fails against it. Laravel counts the entries of either container,
    // so the one bound is owed to each — a `maxLength` would apply to neither.
    $set = new RuleSet([
        'meta' => [ValidationRule::of('nullable'), ValidationRule::of('array'), ValidationRule::of('max', ['5'])],
    ]);

    expect(validationSchema($set, schemaConverter())['properties']['meta'])->toBe([
        'type' => ['array', 'object', 'null'],
        'maxItems' => 5,
        'maxProperties' => 5,
    ]);
});

/** @return list<string> */
function undecidedMessages(array $fields): array
{
    $set = (new RuleSetNormalizer)->normalize(new RuleSet(array_map(
        static fn (array $names): array => array_map(static fn (string $name): ValidationRule => ValidationRule::of($name), $names),
        $fields,
    )));

    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/nodes'),
        actionRef: new ActionRef('', 'App\\C', 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );

    // No source class: these rules are an inline `validate()` call's, so the action bag is every
    // declaration site there is.
    RuleSetNormalizer::report($set, $context, null);

    return array_values(array_map(
        static fn ($d): string => $d->code.': '.$d->message,
        $context->components->diagnostics(),
    ));
}

it('reports the field whose container it could not decide, and only that field', function (): void {
    // The widening is true, so this is an info — but a silent widening is a document the author never
    // learns is wider than their endpoint. The decided siblings say nothing: a notice that fired on the
    // idiomatic `tags`/`tags.*` pair would train everybody to ignore the channel.
    $messages = undecidedMessages([
        'meta' => ['array'],
        'tags' => ['array'],
        'tags.*' => ['string'],
        'address' => ['array'],
        'address.city' => ['string'],
    ]);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toStartWith('validation.container-undecided: ')
        ->and($messages[0])->toContain('"meta"')
        ->and($messages[0])->toContain('documented as either');
});

/**
 * One reader for both declaration sites is only worth what stops a caller reading half of it. A caller
 * that named no source class would weigh the action bag alone and ask for rules a declaration on the
 * TYPE had already answered — a note fired where nothing can be done, in a published document. So the
 * argument is required, and this is the call that proves PHP refuses it.
 */
it('refuses a caller that says nothing about a source class', function (): void {
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/nodes'),
        actionRef: new ActionRef('', 'App\\C', 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );

    /* @phpstan-ignore-next-line arguments.count — the missing argument IS the test */
    expect(static fn () => RuleSetNormalizer::report(new RuleSet(['meta' => [ValidationRule::of('array')]]), $context))
        ->toThrow(ArgumentCountError::class);
});
