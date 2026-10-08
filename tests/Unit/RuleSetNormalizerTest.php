<?php

declare(strict_types=1);

use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\QueryParameter;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

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

    expect(validationSchema($set, schemaConverter())['properties']['meta'])->toBe(blankAsNull([
        'type' => ['array', 'object', 'null'],
        'maxItems' => 5,
        'maxProperties' => 5,
    ]));
});

/**
 * @param  array<string, list<string>>  $fields
 * @param  list<object>  $declarations
 * @return list<string>
 */
function undecidedMessages(array $fields, array $declarations = [], string $verb = 'POST'): array
{
    $set = (new RuleSetNormalizer)->normalize(new RuleSet(array_map(
        static fn (array $names): array => array_map(static fn (string $name): ValidationRule => ValidationRule::of($name), $names),
        $fields,
    )));

    // No source class: these rules are an inline `validate()` call's, so the action bag is every
    // declaration site there is.
    $context = validationRulesContext(declarations: $declarations, verb: $verb);
    RuleSetNormalizer::report($set, $context, null);

    return array_values(array_map(
        static fn ($d): string => $d->code.': '.$d->message,
        $context->components->diagnostics(),
    ));
}

/**
 * The undecided fields `report()` named, in the order it named them, over a rule set of TWO open
 * fields — so a row says which field a declaration settled and not merely how many notes there were.
 *
 * @param  list<object>  $declarations
 * @return list<string>
 */
function undecidedFields(array $declarations = [], string $verb = 'POST'): array
{
    $messages = undecidedMessages(['meta' => ['array'], 'other' => ['array']], $declarations, $verb);

    return array_values(array_map(
        static fn (string $message): string => (string) preg_replace('/^.*Validation field "([^"]+)".*$/s', '$1', $message),
        $messages,
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
 * One reader for both declaration sites is only worth what stops a caller reading half of it, so the
 * argument is required rather than defaulted — and this is the call that proves PHP refuses it.
 */
it('refuses a caller that says nothing about a source class', function (): void {
    $context = validationRulesContext();

    /* @phpstan-ignore arguments.count (the missing argument IS the test) */
    expect(static fn () => RuleSetNormalizer::report(new RuleSet(['meta' => [ValidationRule::of('array')]]), $context))
        ->toThrow(ArgumentCountError::class);
});

/**
 * The declaration half of the note, as ONE table over the whole domain — a body table and a query table
 * each covered their own half and nothing between, so the layers and the verbs are crossed here in full.
 * What clears the question is a function of the layer the rules LAND in, and `DeclaredFields` states how
 * the two writers differ.
 *
 * Two fields, so every row says WHICH one it settled: a declaration that settled the wrong field would
 * otherwise pass. Standing the note down where the document still says "either" hides a widening from
 * the author; keeping it where the document has decided is a note nothing can clear.
 */
it('asks only where a declaration has not already decided the container', function (array $declarations, string $verb, array $reported): void {
    expect(undecidedFields($declarations, $verb))->toBe($reported);
})->with([
    'nothing declared' => [[], 'POST', ['meta', 'other']],

    // The BODY layer at a body verb: a declaration anywhere on the field's branch decides it.
    'a body declaration typed as a free-form map' => [[new BodyParameter(name: 'meta', type: 'object')], 'POST', ['other']],
    'a body declaration typed with a shape' => [[new BodyParameter(name: 'meta', type: 'list<string>')], 'POST', ['other']],
    // A declaration with no type states no shape, so it is written over the field without replacing it:
    // the rules' "either" is what the document still says, and the question stands.
    'a body declaration with no type' => [[new BodyParameter(name: 'meta')], 'POST', ['meta', 'other']],
    'a body declaration naming a key inside' => [[new BodyParameter(name: 'meta.scoring')], 'POST', ['other']],
    'a body declaration naming a key deep inside' => [[new BodyParameter(name: 'meta.scoring.scores')], 'POST', ['other']],
    'a body declaration naming a wildcard element' => [[new BodyParameter(name: 'meta.*')], 'POST', ['other']],
    'a body declaration naming a typed key inside' => [[new BodyParameter(name: 'meta.locale', type: 'string')], 'POST', ['other']],

    // …and the words and names that decide nothing. `array` is the very word the question is about, and
    // a type resolving to no shape publishes the empty schema — wider than the "either" the note names,
    // with the note gone. The read is the write's own parser, so the two agree on what a shape is.
    'a body declaration typed as the word the question is about' => [[new BodyParameter(name: 'meta', type: 'array')], 'POST', ['meta', 'other']],
    'a body declaration typed as mixed' => [[new BodyParameter(name: 'meta', type: 'mixed')], 'POST', ['meta', 'other']],
    'a body declaration naming a sibling field' => [[new BodyParameter(name: 'unrelated.key')], 'POST', ['meta', 'other']],
    // A path with an empty segment names no field, is reported as that mistake, and documents nothing —
    // so there is nothing for it to have settled.
    'a body declaration with a trailing dot' => [[new BodyParameter(name: 'meta.')], 'POST', ['meta', 'other']],
    'a body declaration with a doubled dot' => [[new BodyParameter(name: 'meta..scoring')], 'POST', ['meta', 'other']],
    // The escape is why this is a path comparison and not a string prefix: `meta\.scoring` is one field
    // whose own name holds a dot, and it says nothing about what `meta` is.
    'a body declaration whose name holds a dot' => [[new BodyParameter(name: 'meta\.scoring')], 'POST', ['meta', 'other']],

    // The verb axis, over one declaration that would settle the field if it could reach it: `report()`
    // runs ahead of the verb branch, and a read verb sends the rules to QUERY parameters instead of a
    // body, which a #[BodyParameter] never reaches.
    'a body declaration at put' => [[new BodyParameter(name: 'meta.scoring')], 'PUT', ['other']],
    'a body declaration at patch' => [[new BodyParameter(name: 'meta.scoring')], 'PATCH', ['other']],
    'a body declaration at get' => [[new BodyParameter(name: 'meta.scoring')], 'GET', ['meta', 'other']],
    'a body declaration at head' => [[new BodyParameter(name: 'meta.scoring')], 'HEAD', ['meta', 'other']],

    // The QUERY layer, which mints one parameter per name: only a type stated AT the field decides.
    'a query declaration with a deciding type' => [[new QueryParameter(name: 'meta', type: 'object')], 'GET', ['other']],
    // Nothing is written for a query declaration with no type, so the recovered "either" still stands…
    'a query declaration with no type' => [[new QueryParameter(name: 'meta')], 'GET', ['meta', 'other']],
    // …and a bracketed one patches a property of the parameter without touching the parameter's own
    // type, so it leaves the question exactly as open as it found it.
    'a query declaration naming a key inside' => [[new QueryParameter(name: 'meta[locale]', type: 'string')], 'GET', ['meta', 'other']],
    'a query declaration at a body verb' => [[new QueryParameter(name: 'meta', type: 'object')], 'POST', ['meta', 'other']],
    // A nameless one documents no parameter on a route — the name is optional only where a filter class
    // supplies it — so it settles no field, whatever type it states.
    'a query declaration naming nothing' => [[new QueryParameter(type: 'object')], 'GET', ['meta', 'other']],
]);

/**
 * The field the note is about is the field the declaration has to be read against. A container named
 * ABOVE an undecided field is written over it whole, so the field is not in the document at all — and a
 * note saying it is "documented as either" is false of the build that emitted it, with a remedy that
 * changes nothing.
 */
it('says nothing about a field a declaration above it replaces', function (): void {
    expect(undecidedMessages(
        ['meta.tags' => ['array'], 'meta.name' => ['string']],
        [new BodyParameter(name: 'meta', type: 'object')],
    ))->toBe([]);
});

it('still asks about a field under a declaration that states no shape, which replaces nothing', function (?string $type): void {
    // Written over `meta` rather than in its place, so `meta.tags` is published as the rules left it.
    expect(undecidedMessages(
        ['meta.tags' => ['array'], 'meta.name' => ['string']],
        [new BodyParameter(name: 'meta', type: $type, description: 'Order metadata.')],
    ))->toHaveCount(1);
})->with(['no type' => [null], 'the word the question is about' => ['array'], 'mixed' => ['mixed']]);

/*
 * Exclude rules. Laravel runs a field's rules in the order written and, the moment an exclude rule fires,
 * runs none of the rest (`Validator::passes()` breaks on `shouldBeExcluded()` after every rule). So a
 * presence rule written AFTER one holds only on the requests that keep the field, and one written before
 * it holds on every request. Rule ORDER is the fact here, which is why it is settled on the rule set: the
 * chain sees each field's rules sorted by effect.
 */
$gatedRules = static function (string $pipe): string {
    $normalized = (new RuleSetNormalizer)->normalize(new RuleSet(['f' => RuleParsing::tokens($pipe)]))->fields;

    return implode('|', array_map(
        static fn (ValidationRule $rule): string => $rule->parameters === [] ? $rule->name : $rule->name.':'.implode(',', $rule->parameters),
        $normalized['f'] ?? [],
    ));
};

it('reads a presence rule written after an exclude rule as holding only while the field is kept', function (string $rules, string $expected) use ($gatedRules): void {
    expect($gatedRules($rules))->toBe($expected);
})->with([
    'exclude_if, required' => ['exclude_if:kind,a,b|required|string', 'exclude_if:kind,a,b|required_unless:kind,a,b|string'],
    'exclude_unless, required' => ['exclude_unless:kind,a|required|string', 'exclude_unless:kind,a|required_if:kind,a|string'],
    'exclude_without, required' => ['exclude_without:first,last|required|string', 'exclude_without:first,last|required_with_all:first,last|string'],
    'exclude_if, prohibited' => ['exclude_if:kind,a|prohibited', 'exclude_if:kind,a|prohibited_unless:kind,a'],
    'exclude_unless, prohibited' => ['exclude_unless:kind,a|prohibited', 'exclude_unless:kind,a|prohibited_if:kind,a'],
    // No Laravel rule states these conditions exactly (see the oracle test below), so the rule drops and
    // the field is optional and sendable, with no note that would claim more than the server does.
    'exclude_with, required' => ['exclude_with:other|required|string', 'exclude_with:other|string'],
    'exclude_if, present' => ['exclude_if:kind,a|present', 'exclude_if:kind,a'],
    'exclude_unless, present' => ['exclude_unless:kind,a|present', 'exclude_unless:kind,a'],
    'exclude_with, present' => ['exclude_with:other|present', 'exclude_with:other'],
    'exclude_without, present' => ['exclude_without:other|present', 'exclude_without:other'],
    'exclude_with, prohibited' => ['exclude_with:other|prohibited', 'exclude_with:other'],
    'exclude_without, prohibited' => ['exclude_without:other|prohibited', 'exclude_without:other'],
    'exclude_unless on a listed null, required' => ['exclude_unless:kind,a,null|required', 'exclude_unless:kind,a,null'],
    'exclude_if on a listed null, prohibited' => ['exclude_if:kind,NULL|prohibited', 'exclude_if:kind,NULL'],
]);

it('restates a gated presence rule only as the rule Laravel itself agrees with', function (string $gated, string $restated): void {
    // Laravel is the oracle: over every state the other field can be in, the field left out must pass or
    // fail the restated rule exactly as it does the rules as written. That is what makes the "Required
    // when …" note true rather than near enough.
    foreach ([[], ['kind' => 'a'], ['kind' => 'b'], ['kind' => null], ['kind' => ''], ['kind' => 'a', 'other' => null], ['other' => ''], ['other' => 'x'], ['first' => 'x'], ['first' => 'x', 'last' => 'y'], ['first' => 'x', 'last' => '']] as $data) {
        foreach ([$data, $data + ['f' => 'sent']] as $body) {
            expect(Illuminate\Support\Facades\Validator::make($body, ['f' => $restated])->passes())
                ->toBe(Illuminate\Support\Facades\Validator::make($body, ['f' => $gated])->passes(), $gated.' on '.json_encode($body));
        }
    }
})->with([
    ['exclude_if:kind,a,b|required', 'required_unless:kind,a,b'],
    ['exclude_unless:kind,a|required', 'required_if:kind,a'],
    ['exclude_without:first,last|required', 'required_with_all:first,last'],
    ['exclude_if:kind,a|prohibited', 'prohibited_unless:kind,a'],
    ['exclude_unless:kind,a|prohibited', 'prohibited_if:kind,a'],
]);

it('has no exact restatement for the pairs it drops', function (string $gated, string $nearest): void {
    // The negative half of the oracle: the nearest-looking rule disagrees with Laravel somewhere, which
    // is why these drop rather than publish its note.
    $disagrees = false;
    foreach ([[], ['kind' => 'a'], ['kind' => null], ['other' => null], ['other' => ''], ['other' => 'x']] as $data) {
        foreach ([$data, $data + ['f' => null], $data + ['f' => 'sent']] as $body) {
            $disagrees = $disagrees || Illuminate\Support\Facades\Validator::make($body, ['f' => $nearest])->passes()
                !== Illuminate\Support\Facades\Validator::make($body, ['f' => $gated])->passes();
        }
    }

    expect($disagrees)->toBeTrue();
})->with([
    'exclude_with tests presence, required_without tests value' => ['exclude_with:other|required', 'required_without:other'],
    'present admits null, required does not' => ['exclude_unless:kind,a|present', 'required_if:kind,a'],
    'a listed null matches an absent field in one rule only' => ['exclude_unless:kind,a,null|required', 'required_if:kind,a,null'],
]);

it('leaves a presence rule written before the exclude rule as it is, since it runs on every request', function (string $rules) use ($gatedRules): void {
    expect($gatedRules($rules))->toBe($rules);
})->with([
    'required, exclude_if' => ['required|exclude_if:kind,a|string'],
    'present, exclude_unless' => ['present|exclude_unless:kind,a|string'],
    'required, exclude_with' => ['required|exclude_with:other'],
    'required, exclude_without' => ['required|exclude_without:other'],
    'required, exclude' => ['required|exclude'],
    // Not a presence rule: nothing about it depends on the field being kept.
    'exclude_if, sometimes, nullable, filled, required_if' => ['exclude_if:kind,a|sometimes|nullable|filled|required_if:x,y'],
]);

it('drops every rule written after a bare exclude, which Laravel never runs', function () use ($gatedRules): void {
    expect($gatedRules('string|exclude|required|integer|max:3'))->toBe('string|exclude');
});

it('drops a gated presence rule no single rule can restate, leaving the field optional', function (string $rules, string $expected) use ($gatedRules): void {
    // Two exclude rules keep the field only when both do — one conjunction, which no Laravel rule
    // spells; an exclude rule with no parameters is one whose condition could not be read.
    expect($gatedRules($rules))->toBe($expected);
})->with([
    'two exclude rules' => ['exclude_if:kind,a|exclude_with:other|required|string', 'exclude_if:kind,a|exclude_with:other|string'],
    'a condition that could not be read' => ['exclude_if|required|string', 'exclude_if|string'],
    'a callback exclude_unless' => ['exclude_unless|required', 'exclude_unless'],
    'too few parameters' => ['exclude_unless:kind|required', 'exclude_unless:kind'],
]);

it('keeps a field prohibited only while it is kept, which is sendable', function (): void {
    expect(normalizedNames([
        'legacy' => ['exclude_with', 'prohibited'],
        'legacy.id' => ['string'],
    ]))->toBe([
        'legacy' => ['exclude_with'],
        'legacy.id' => ['string'],
    ]);
});

it('gates on every rule Laravel excludes a field by', function (): void {
    // The datasets above list the family by hand; Laravel's own list is the source of truth. A rule it
    // adds to that list and nothing here recognises would leave a `required` after it unconditional.
    $excludeRules = (new ReflectionProperty(Validator::class, 'excludeRules'))->getDefaultValue();
    expect($excludeRules)->toBeArray()->not->toBeEmpty();

    $gated = array_keys((array) (new ReflectionClassConstant(RuleSetNormalizer::class, 'KEPT_WHEN'))->getValue());

    /** @var list<string> $excludeRules */
    expect(array_map(static fn (string $rule): string => Str::snake($rule), $excludeRules))
        ->toEqualCanonicalizing(['exclude', ...$gated]);
});
