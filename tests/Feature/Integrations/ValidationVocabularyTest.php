<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;

/**
 * Exercises the Laravel rule vocabulary (the transformer set + effect-order ranking) driving the
 * core chain — the Laravel-side counterpart to the core driver's vocabulary-free unit test.
 */
function vocabularyContext(RepresentationPolicy $policy = new RepresentationPolicy): SchemaConverter
{
    return new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, $policy);
}

/**
 * Recover rules from `field => 'pipe|string'` shorthand, order them Laravel-style, and convert.
 *
 * @param  array<string, string>  $fields
 */
function convertLaravelRules(array $fields, RepresentationPolicy $policy = new RepresentationPolicy): ValidationSchema
{
    $set = [];
    foreach ($fields as $field => $pipe) {
        $set[$field] = RuleParsing::tokens($pipe);
    }

    $ordered = (new RuleOrdering)->order(new RuleSet($set));

    return (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))->convert($ordered, vocabularyContext($policy));
}

/**
 * Convert one field's rules built as `[name, parameters, note?]` tuples, through the same ordering +
 * core chain the real integrations use. This low-level path can express rules (enum values, exists
 * table args) that `pipe|string` shorthand cannot.
 *
 * @param  list<array{0: string, 1?: list<string>, 2?: string}>  $rules
 */
function convertFieldRules(array $rules): ValidationSchema
{
    $ruleObjects = array_map(
        static fn (array $r): ValidationRule => ValidationRule::of($r[0], $r[1] ?? [], $r[2] ?? null),
        $rules,
    );

    $ordered = (new RuleOrdering)->order(new RuleSet(['f' => $ruleObjects]));

    return (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))->convert($ordered, vocabularyContext());
}

/**
 * The floor list (docs/testing.md): every string-rule entry across every transformer gets a row here
 * asserting its schema effect. Presence/cross-field/multipart effects don't surface on the property
 * schema, so they're covered by the dedicated tests below — between them, every table entry is proven.
 *
 * Each row also pins the synthesized `example`, so this doubles as the rule → example table: a row
 * WITHOUT one is a rule that earns no example, and the reason is on the row. The three ways that
 * happens are all here — the rule pinned only a base type (`string`, `integer`), it left the value
 * constrained outside the schema (`decimal`, `before`, `file`), or the value it wants contradicts the
 * schema the same rule published (`date_format` with a time token).
 */
it('maps every schema-producing string rule to its fragment', function (array $rules, array $expected): void {
    $property = convertFieldRules($rules)->schema['properties']['f'];
    ksort($property);
    ksort($expected);

    expect($property)->toBe($expected);
})->with([
    // TypeRuleTransformer — base type + format entries.
    'string' => [[['string']], ['type' => 'string']],
    'integer' => [[['integer']], ['type' => 'integer']],
    'int' => [[['int']], ['type' => 'integer']],
    'numeric' => [[['numeric']], ['type' => 'number']],
    'boolean' => [[['boolean']], ['type' => 'boolean', 'example' => true]],
    'bool' => [[['bool']], ['type' => 'boolean', 'example' => true]],
    'array' => [[['array']], ['type' => 'array']],
    'email' => [[['email']], ['format' => 'email', 'type' => 'string', 'example' => 'user@example.com']],
    'uuid' => [[['uuid']], ['format' => 'uuid', 'type' => 'string', 'example' => '3fa85f64-5717-4562-b3fc-2c963f66afa6']],
    'ulid' => [[['ulid']], ['format' => 'ulid', 'type' => 'string', 'example' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']],
    'url' => [[['url']], ['format' => 'uri', 'type' => 'string', 'example' => 'https://example.com']],
    'ip' => [[['ip']], ['format' => 'ip', 'type' => 'string', 'example' => '192.0.2.1']],
    'date' => [[['date']], ['format' => 'date', 'type' => 'string', 'example' => '2024-01-01']],

    // ChoiceRuleTransformer — string set and numeric set, plus the enum-FQCN note.
    'in (string set)' => [[['in', ['draft', 'published']]], ['enum' => ['draft', 'published'], 'type' => 'string', 'example' => 'draft']],
    'in (numeric set)' => [[['in', ['1', '2', '3']]], ['enum' => [1, 2, 3], 'type' => 'integer', 'example' => 1]],
    'enum (folded values + note)' => [[['enum', ['a', 'b'], 'App\\Enums\\Kind']], ['description' => 'App\\Enums\\Kind', 'enum' => ['a', 'b'], 'type' => 'string', 'example' => 'a']],
    // Empty value set: the rule is consumed but contributes no enum (a bare typed field remains).
    'in (empty values)' => [[['string'], ['in', []]], ['type' => 'string']],
    // Already-typed guard: an explicit type is preserved, never overridden by the value-inferred one.
    'in (preserves an existing type)' => [[['integer'], ['in', ['a', 'b']]], ['enum' => ['a', 'b'], 'type' => 'integer']],

    // SizeRuleTransformer — type-aware min/max/between/size.
    'min (string length)' => [[['string'], ['min', ['2']]], ['minLength' => 2, 'type' => 'string', 'example' => 'example']],
    'max (string length)' => [[['string'], ['max', ['9']]], ['maxLength' => 9, 'type' => 'string', 'example' => 'example']],
    'between (numeric bounds)' => [[['integer'], ['between', ['1', '5']]], ['maximum' => 5, 'minimum' => 1, 'type' => 'integer', 'example' => 1]],
    'size (array items)' => [[['array'], ['size', ['3']]], ['maxItems' => 3, 'minItems' => 3, 'type' => 'array']],
    // Float bounds on a numeric field keep their decimal value (not truncated to int).
    'min (float bound)' => [[['numeric'], ['min', ['2.5']]], ['minimum' => 2.5, 'type' => 'number', 'example' => 2.5]],
    'max (float bound)' => [[['numeric'], ['max', ['9.75']]], ['maximum' => 9.75, 'type' => 'number', 'example' => 1]],

    // DateFormatRuleTransformer — date-only vs time-bearing pattern.
    'date_format (date)' => [[['date_format', ['Y-m-d']]], ['description' => 'Expected format: Y-m-d', 'format' => 'date', 'type' => 'string', 'example' => '2024-01-01']],
    'date_format (date-time)' => [[['date_format', ['Y-m-d H:i:s']]], ['description' => 'Expected format: Y-m-d H:i:s', 'format' => 'date-time', 'type' => 'string']],

    // RegexRuleTransformer — delimiters stripped to a bare ECMA-262 pattern.
    'regex' => [[['regex', ['/^[a-z]+$/']]], ['pattern' => '^[a-z]+$', 'type' => 'string', 'example' => 'example']],

    // ExistsRuleTransformer — a FK reference contributes a default string type only.
    'exists' => [[['exists', ['users', 'id']]], ['type' => 'string']],
    'unique' => [[['unique', ['users']]], ['type' => 'string']],
    // Already-typed guard: an existing integer type survives a FK-reference rule.
    'exists (preserves an existing type)' => [[['integer'], ['exists', ['users', 'id']]], ['type' => 'integer']],
    // RegexRuleTransformer already-typed guard: an explicit type is preserved alongside the pattern.
    'regex (preserves an existing type)' => [[['integer'], ['regex', ['/^\\d+$/']]], ['pattern' => '^\\d+$', 'type' => 'integer']],

    // FileRuleTransformer — binary string schema (multipart switch asserted separately).
    'file' => [[['file']], ['format' => 'binary', 'type' => 'string']],
    'image' => [[['image']], ['description' => 'An image file.', 'format' => 'binary', 'type' => 'string']],

    // AlphaRuleTransformer — canonical ECMA-262 character-class patterns.
    'alpha' => [[['alpha']], ['pattern' => '^[a-zA-Z]+$', 'type' => 'string', 'example' => 'example']],
    'alpha_num' => [[['alpha_num']], ['pattern' => '^[a-zA-Z0-9]+$', 'type' => 'string', 'example' => 'example']],
    'alpha_dash' => [[['alpha_dash']], ['pattern' => '^[a-zA-Z0-9_-]+$', 'type' => 'string', 'example' => 'example']],

    // AffixRuleTransformer — single value → anchored pattern (literal regex-escaped); multi → description.
    'starts_with (single → pattern)' => [[['starts_with', ['abc']]], ['pattern' => '^abc', 'type' => 'string', 'example' => 'abcexample']],
    'ends_with (single → pattern, escaped)' => [[['ends_with', ['.png']]], ['pattern' => '\\.png$', 'type' => 'string', 'example' => 'example.png']],
    'starts_with (multi → description)' => [[['starts_with', ['a', 'b']]], ['description' => 'Must start with one of: a, b.', 'type' => 'string', 'example' => 'aexample']],
    'ends_with (multi → description)' => [[['ends_with', ['x', 'y']]], ['description' => 'Must end with one of: x, y.', 'type' => 'string', 'example' => 'examplex']],

    // DigitsRuleTransformer — a digit count is a string pattern (leading zeros preserved), never an int.
    'digits' => [[['digits', ['5']]], ['pattern' => '^\\d{5}$', 'type' => 'string', 'example' => '12345']],
    'digits_between' => [[['digits_between', ['2', '5']]], ['pattern' => '^\\d{2,5}$', 'type' => 'string', 'example' => '12']],
    'max_digits' => [[['max_digits', ['4']]], ['pattern' => '^\\d{1,4}$', 'type' => 'string', 'example' => '1']],
    'min_digits' => [[['min_digits', ['2']]], ['pattern' => '^\\d{2,}$', 'type' => 'string', 'example' => '12']],

    // JsonRuleTransformer — a string carrying a JSON document.
    'json' => [[['json']], ['contentMediaType' => 'application/json', 'type' => 'string', 'example' => '{}']],

    // TimezoneRuleTransformer — no JSON-Schema format exists; documented as a described string.
    'timezone' => [[['timezone']], ['description' => 'Must be a valid timezone identifier.', 'type' => 'string', 'example' => 'UTC']],

    // DateComparisonRuleTransformer — description + format when the target is a parseable date.
    'before' => [[['before', ['2024-01-01']]], ['description' => 'Must be a date before 2024-01-01.', 'format' => 'date', 'type' => 'string']],
    'before_or_equal' => [[['before_or_equal', ['2024-01-01']]], ['description' => 'Must be a date on or before 2024-01-01.', 'format' => 'date', 'type' => 'string']],
    'after' => [[['after', ['2024-01-01']]], ['description' => 'Must be a date after 2024-01-01.', 'format' => 'date', 'type' => 'string']],
    'after_or_equal (date-time target)' => [[['after_or_equal', ['2024-01-01 09:00:00']]], ['description' => 'Must be a date on or after 2024-01-01 09:00:00.', 'format' => 'date-time', 'type' => 'string']],

    // BooleanConstRuleTransformer — accepted/declined fix a boolean const; the _if forms add a condition.
    'accepted' => [[['accepted']], ['const' => true, 'type' => 'boolean']],
    'declined' => [[['declined']], ['const' => false, 'type' => 'boolean']],
    'accepted_if' => [[['accepted_if', ['terms', 'yes']]], ['const' => true, 'description' => 'Must be accepted when terms is yes.', 'type' => 'boolean']],
    'declined_if' => [[['declined_if', ['spam', '1']]], ['const' => false, 'description' => 'Must be declined when spam is 1.', 'type' => 'boolean']],

    // NotInRuleTransformer — the mirror of `in`: not: {enum}. Numeric-set inference like ChoiceRuleTransformer.
    'not_in (string set)' => [[['not_in', ['draft', 'deleted']]], ['not' => ['enum' => ['draft', 'deleted']], 'type' => 'string']],
    'not_in (numeric set)' => [[['not_in', ['1', '2']]], ['not' => ['enum' => [1, 2]], 'type' => 'integer']],

    // NumericRuleTransformer — decimal note, multipleOf, and the numeric-literal comparison bounds.
    'decimal (fixed)' => [[['decimal', ['2']]], ['description' => 'Must have 2 decimal places.', 'type' => 'number']],
    'decimal (range)' => [[['decimal', ['2', '4']]], ['description' => 'Must have between 2 and 4 decimal places.', 'type' => 'number']],
    'multiple_of' => [[['multiple_of', ['5']]], ['multipleOf' => 5, 'type' => 'number', 'example' => 5]],
    'multiple_of (float)' => [[['multiple_of', ['0.5']]], ['multipleOf' => 0.5, 'type' => 'number', 'example' => 1]],
    'gt (numeric literal)' => [[['gt', ['0']]], ['exclusiveMinimum' => 0, 'type' => 'number', 'example' => 1]],
    'gte (numeric literal)' => [[['gte', ['1']]], ['minimum' => 1, 'type' => 'number', 'example' => 1]],
    'lt (numeric literal)' => [[['lt', ['100']]], ['exclusiveMaximum' => 100, 'type' => 'number', 'example' => 1]],
    'lte (numeric literal)' => [[['lte', ['99']]], ['maximum' => 99, 'type' => 'number', 'example' => 1]],

    // ArrayShapeRuleTransformer — list → array; distinct → uniqueItems.
    'list' => [[['list']], ['type' => 'array']],
    'distinct' => [[['distinct']], ['type' => 'array', 'uniqueItems' => true]],

    // AdditionalPropertiesRuleTransformer — a recovered `array<string, V>`: the value schema arrives as
    // JSON, and `object` replaces the `array` a Laravel type rule can only have said.
    'additional_properties' => [[['additional_properties', ['{"type":"string"}']]], ['additionalProperties' => ['type' => 'string'], 'type' => 'object']],
    'additional_properties (open values)' => [[['additional_properties', ['{}']]], ['additionalProperties' => [], 'type' => 'object']],
    'additional_properties (overrides the array rule beside it)' => [[['array'], ['additional_properties', ['{"type":"integer"}']]], ['additionalProperties' => ['type' => 'integer'], 'type' => 'object']],
    // Degradation: nothing to decode, or a parameter that isn't a schema, leaves the field untouched.
    'additional_properties (no parameter)' => [[['string'], ['additional_properties']], ['type' => 'string']],
    'additional_properties (unparseable parameter)' => [[['string'], ['additional_properties', ['not json']]], ['type' => 'string']],

    // ProhibitedRuleTransformer — the conditional forms stay documented, with the condition as a note.
    // (A bare `prohibited` never reaches a field at all; RuleSetNormalizerTest covers that.)
    'prohibited (no schema effect)' => [[['string'], ['prohibited']], ['type' => 'string']],
    'prohibited_if' => [[['string'], ['prohibited_if', ['status', 'locked']]], ['description' => 'Must not be sent when status is locked.', 'type' => 'string']],
    'prohibited_if (presence form)' => [[['string'], ['prohibited_if', ['status']]], ['description' => 'Must not be sent when status is present.', 'type' => 'string']],
    'prohibited_unless' => [[['string'], ['prohibited_unless', ['role', 'admin']]], ['description' => 'Must not be sent unless role is admin.', 'type' => 'string']],
    'prohibited_unless (presence form)' => [[['string'], ['prohibited_unless', ['role']]], ['description' => 'Must not be sent unless role is present.', 'type' => 'string']],
    'prohibits' => [[['string'], ['prohibits', ['legacy_name']]], ['description' => 'Sending this field requires legacy_name to be absent.', 'type' => 'string']],
    'prohibits (no parameters)' => [[['string'], ['prohibits']], ['type' => 'string']],

    // FileRuleTransformer — dimensions has no OpenAPI keyword, so the constraint list is a note
    // (multipart switch asserted separately).
    'dimensions' => [[['dimensions', ['min_width=100', 'min_height=200']]], ['description' => 'Image dimensions: min_width=100, min_height=200.']],

    // AnnotationRuleTransformer — the keywords a #[RuleSchema] states outright. An example is coerced
    // back to the field's resolved type, having travelled as a string parameter.
    'format' => [[['string'], ['format', ['iban']]], ['format' => 'iban', 'type' => 'string']],
    'format (never overwrites a type rule\'s own)' => [[['email'], ['format', ['iban']]], ['format' => 'email', 'type' => 'string', 'example' => 'user@example.com']],
    'description' => [[['string'], ['description', ['A bank reference.']]], ['description' => 'A bank reference.', 'type' => 'string']],
    'description (appends to an earlier note)' => [[['timezone'], ['description', ['Europe only.']]], ['description' => 'Must be a valid timezone identifier. Europe only.', 'type' => 'string', 'example' => 'UTC']],
    'example (string)' => [[['string'], ['example', ['GB123456']]], ['example' => 'GB123456', 'type' => 'string']],
    'example (integer)' => [[['integer'], ['example', ['42']]], ['example' => 42, 'type' => 'integer']],
    'example (number)' => [[['numeric'], ['example', ['1.25']]], ['example' => 1.25, 'type' => 'number']],
    'example (boolean)' => [[['boolean'], ['example', ['true']]], ['example' => true, 'type' => 'boolean']],
    // An author's example outranks a synthesized one — the format's sample never overwrites it.
    'example (outranks the synthesized one)' => [[['email'], ['example', ['ops@example.test']]], ['example' => 'ops@example.test', 'format' => 'email', 'type' => 'string']],

    // Degradations of the synthesis itself: bounds that cross admit no value; a floor so high the
    // sample would stop illustrating anything; a format sample the length bound then rejects. Each
    // publishes no example rather than one the endpoint's own validator would refuse.
    'no example (bounds cross)' => [[['integer'], ['min', ['10']], ['max', ['5']]], ['maximum' => 5, 'minimum' => 10, 'type' => 'integer']],
    'no example (length floor above the cap)' => [[['string'], ['min', ['100']]], ['minLength' => 100, 'type' => 'string']],
    'no example (format sample too long for the bound)' => [[['email'], ['max', ['5']]], ['format' => 'email', 'maxLength' => 5, 'type' => 'string']],
    'no example (unknown format states no sample)' => [[['string'], ['format', ['iban']]], ['format' => 'iban', 'type' => 'string']],
]);

it('normalises regex delimiters to a bare ECMA-262 pattern across delimiter styles', function (string $raw, string $expected): void {
    $property = convertFieldRules([['regex', [$raw]]])->schema['properties']['f'];

    expect($property['pattern'])->toBe($expected)
        ->and($property['type'])->toBe('string');
})->with([
    'slash' => ['/^[a-z]+$/', '^[a-z]+$'],
    'hash delimiter' => ['#^[a-z]+$#', '^[a-z]+$'],
    'tilde + trailing flags' => ['~^\\d+$~i', '^\\d+$'],
    'brace bracket-pair' => ['{^[0-9]+$}', '^[0-9]+$'],
    'paren bracket-pair' => ['(hello)', 'hello'],
    'angle bracket-pair' => ['<^x$>', '^x$'],
    'too short → kept verbatim' => ['x', 'x'],
    'no closing delimiter → kept verbatim' => ['/abc', '/abc'],
]);

it('applies every presence-rule entry to the required/nullable contract', function (): void {
    // required / present mark the field required.
    expect(convertFieldRules([['string'], ['required']])->schema['required'] ?? [])->toBe(['f']);
    expect(convertFieldRules([['string'], ['present']])->schema['required'] ?? [])->toBe(['f']);

    // filled means "non-empty when present", so it doesn't require presence — the field stays optional.
    expect(convertFieldRules([['string'], ['filled']])->schema)->not->toHaveKey('required');

    // sometimes leaves the field optional (no `required` list emitted).
    expect(convertFieldRules([['string'], ['sometimes']])->schema)->not->toHaveKey('required');

    // nullable widens the type to allow null (2020-12 default policy → `[t, null]`).
    expect(convertFieldRules([['string'], ['nullable']])->schema['properties']['f']['type'])->toBe(['string', 'null']);
});

it('resolves sometimes|required as optional regardless of rule order', function (): void {
    // "required when present" is optional in the request contract, order-independent.
    expect(convertFieldRules([['string'], ['sometimes'], ['required']])->schema)->not->toHaveKey('required');
    expect(convertFieldRules([['string'], ['required'], ['sometimes']])->schema)->not->toHaveKey('required');
    // required|filled is still required (filled has no presence effect).
    expect(convertFieldRules([['string'], ['required'], ['filled']])->schema['required'] ?? [])->toBe(['f']);
});

it('documents a file size bound in KB as a description note, never a length keyword', function (): void {
    $max = convertFieldRules([['file'], ['max', ['2048']]])->schema['properties']['f'];
    expect($max)->not->toHaveKey('maxLength')
        ->and($max['format'])->toBe('binary')
        ->and($max['description'])->toBe('Maximum file size: 2048 KB.');

    $between = convertFieldRules([['file'], ['between', ['10', '2048']]])->schema['properties']['f'];
    expect($between)->not->toHaveKey('maxLength')
        ->and($between['description'])->toBe('File size must be between 10 and 2048 KB.');

    $size = convertFieldRules([['file'], ['size', ['500']]])->schema['properties']['f'];
    expect($size['description'])->toBe('File size must be exactly 500 KB.');
});

it('switches to multipart on mimes/mimetypes/extensions but adds nothing else', function (): void {
    foreach (['mimes', 'mimetypes', 'extensions'] as $rule) {
        $result = convertFieldRules([['file'], [$rule, ['pdf']]]);
        expect($result->mediaType)->toBe('multipart/form-data');
    }

    // Bare mimes (no accompanying file rule) still flips multipart, and contributes no keyword.
    $bare = convertFieldRules([['mimes', ['pdf', 'doc']]]);
    expect($bare->mediaType)->toBe('multipart/form-data')
        ->and($bare->schema['properties']['f'])->toBe([]);
});

it('flips multipart on dimensions and notes the constraints, and leaves prohibited fields optional', function (): void {
    // `dimensions` implies an uploaded image: multipart, plus a description note (no wrong keyword).
    // Alongside `image` it appends to the image note rather than clobbering it.
    $dimensions = convertFieldRules([['image'], ['dimensions', ['min_width=100', 'max_width=1000']]]);
    expect($dimensions->mediaType)->toBe('multipart/form-data')
        ->and($dimensions->schema['properties']['f']['description'])->toContain('Image dimensions: min_width=100, max_width=1000.')
        ->and($dimensions->diagnostics)->toBe([]);

    // Bare `dimensions` with no params still flips multipart but adds no note.
    $bare = convertFieldRules([['dimensions']]);
    expect($bare->mediaType)->toBe('multipart/form-data')
        ->and($bare->schema['properties']['f'])->toBe([]);

    // Presence-negations reaching the chain: consumed with no schema effect, field optional. An
    // unconditional `prohibited` is dropped from the rule set before this point (RuleSetNormalizerTest).
    foreach (['prohibited', 'prohibits'] as $rule) {
        $result = convertFieldRules([['string'], [$rule]]);
        expect($result->schema['properties']['f'])->toBe(['type' => 'string'])
            ->and($result->schema)->not->toHaveKey('required')
            ->and($result->diagnostics)->toBe([]);
    }
});

it('consumes known no-op rules without a diagnostic or schema effect', function (): void {
    foreach (['bail', 'exclude', 'exclude_if', 'exclude_unless', 'exclude_with', 'exclude_without', 'current_password'] as $rule) {
        $result = convertFieldRules([['string'], [$rule]]);
        expect($result->schema['properties']['f'])->toBe(['type' => 'string'])
            ->and($result->diagnostics)->toBe([]);
    }
});

it('describes conditional-required rules and leaves the field optional', function (array $rule, string $note): void {
    $result = convertFieldRules([['string'], $rule]);

    expect($result->schema)->not->toHaveKey('required')
        ->and($result->schema['properties']['f']['description'])->toBe($note)
        ->and($result->diagnostics)->toBe([]);
})->with([
    'required_if' => [['required_if', ['status', 'active']], 'Required when status is active.'],
    'required_unless' => [['required_unless', ['role', 'admin']], 'Required unless role is admin.'],
    'required_with' => [['required_with', ['first', 'last']], 'Required when any of first, last is present.'],
    'required_with_all' => [['required_with_all', ['first', 'last']], 'Required when first, last are all present.'],
    'required_without' => [['required_without', ['first', 'last']], 'Required when any of first, last is absent.'],
    'required_without_all' => [['required_without_all', ['first', 'last']], 'Required when first, last are all absent.'],
]);

it('documents the confirmed partner and switches file rules to multipart', function (): void {
    $confirmed = convertFieldRules([['string'], ['required'], ['confirmed']]);
    expect($confirmed->schema['properties'])->toHaveKey('f_confirmation')
        ->and($confirmed->schema['properties']['f_confirmation'])->toBe(['type' => 'string'])
        ->and($confirmed->schema['required'])->toBe(['f', 'f_confirmation']);

    expect(convertFieldRules([['file']])->mediaType)->toBe('multipart/form-data')
        ->and(convertFieldRules([['image']])->mediaType)->toBe('multipart/form-data');
});

it('leaves the confirmed partner optional when the field itself is not required', function (): void {
    // A non-required `confirmed` field mirrors its type but neither field joins the required list.
    $confirmed = convertFieldRules([['string'], ['confirmed']]);

    expect($confirmed->schema['properties'])->toHaveKey('f_confirmation')
        ->and($confirmed->schema['properties']['f_confirmation'])->toBe(['type' => 'string'])
        ->and($confirmed->schema)->not->toHaveKey('required');
});

it('applies size rules type-aware and independent of author order', function (): void {
    $direct = convertLaravelRules(['name' => 'string|min:2|max:100'])->schema;
    $reordered = convertLaravelRules(['name' => 'max:100|min:2|string'])->schema;
    $int = convertLaravelRules(['age' => 'integer|between:1,120'])->schema;

    // Same keywords/values whatever the author order; key order (a cosmetic difference the
    // canonicaliser normalises at emit) can vary among equal-rank rules, so compare sorted.
    $reorderedName = $reordered['properties']['name'];
    ksort($reorderedName);

    expect($direct['properties']['name'])->toBe(['type' => 'string', 'minLength' => 2, 'maxLength' => 100, 'example' => 'example'])
        ->and($reorderedName)->toBe(['example' => 'example', 'maxLength' => 100, 'minLength' => 2, 'type' => 'string'])
        ->and($int['properties']['age'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 120, 'example' => 1]);
});

it('maps type + format + choice + regex + date_format rules', function (): void {
    $schema = convertLaravelRules([
        'email' => 'required|email',
        'id' => 'uuid',
        'status' => 'in:draft,published',
        'slug' => 'regex:/^[a-z]+$/',
        'when' => 'date_format:Y-m-d',
    ])->schema;

    expect($schema['properties']['email'])->toBe(['type' => 'string', 'format' => 'email', 'example' => 'user@example.com'])
        ->and($schema['properties']['id'])->toBe(['type' => 'string', 'format' => 'uuid', 'example' => '3fa85f64-5717-4562-b3fc-2c963f66afa6'])
        ->and($schema['properties']['status'])->toBe(['type' => 'string', 'enum' => ['draft', 'published'], 'example' => 'draft'])
        ->and($schema['properties']['slug'])->toBe(['type' => 'string', 'pattern' => '^[a-z]+$', 'example' => 'example'])
        ->and($schema['properties']['when'])->toBe(['type' => 'string', 'format' => 'date', 'description' => 'Expected format: Y-m-d', 'example' => '2024-01-01'])
        ->and($schema['required'])->toBe(['email']);
});

it('switches to multipart and documents the confirmed partner', function (): void {
    $file = convertLaravelRules(['avatar' => 'required|image', 'name' => 'string']);
    $confirmed = convertLaravelRules(['password' => 'required|string|confirmed'])->schema;

    expect($file->mediaType)->toBe('multipart/form-data')
        ->and($file->schema['properties']['avatar'])->toBe(['type' => 'string', 'format' => 'binary', 'description' => 'An image file.'])
        ->and($confirmed['properties'])->toHaveKeys(['password', 'password_confirmation'])
        ->and($confirmed['required'])->toBe(['password', 'password_confirmation']);
});

/**
 * The vocabulary guard: the dataset is derived from every transformer's declared handledRuleNames(),
 * so a name added to a transformer without a row here fails automatically. Each declared name must
 * route to exactly one transformer — no gap (a declared name its own supports() rejects) and no
 * overlap (two transformers claiming the same name).
 *
 * @return iterable<string, array{string}>
 */
function handledRuleNameRows(): iterable
{
    foreach (ValidationIntegration::transformers() as $transformer) {
        foreach ($transformer->handledRuleNames() as $name) {
            yield $name => [$name];
        }
    }
}

it('routes every declared rule name to exactly one transformer', function (string $name): void {
    $rule = ValidationRule::of($name);
    $matching = array_filter(
        ValidationIntegration::transformers(),
        static fn ($transformer): bool => $transformer->supports($rule),
    );

    expect($matching)->toHaveCount(1);
})->with(handledRuleNameRows());

it('raises an info diagnostic for a rule no transformer handles', function (): void {
    // `mac_address` is outside the mapped vocabulary, so the field stays permissive and the unhandled
    // contract holds.
    $result = convertLaravelRules(['token' => 'string|mac_address']);

    expect($result->schema['properties']['token'])->toBe(['type' => 'string'])
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics[0]->code)->toBe('validation.rule-unhandled');
});

/**
 * The real Laravel vocabulary honouring `representation.examples.formats`: an `email` rule still writes
 * `format: email`, and the sample beside it is the one the document configured. Only the formats named
 * move — this is the merge, seen from the vocabulary that produces the formats in the first place.
 */
it('illustrates a Laravel format rule with the document\'s configured sample', function (): void {
    $policy = RepresentationPolicy::fromConfig(['examples' => ['formats' => [
        'email' => 'jane@example.com',
        'ip' => '198.51.100.7',
    ]]]);

    $schema = convertLaravelRules([
        'contact' => 'required|email',
        'host' => 'required|ip',
        'site' => 'required|url',
        'ref' => 'required|uuid',
    ], $policy)->schema;

    expect($schema['properties']['contact'])->toBe(['type' => 'string', 'format' => 'email', 'example' => 'jane@example.com'])
        ->and($schema['properties']['host'])->toBe(['type' => 'string', 'format' => 'ip', 'example' => '198.51.100.7'])
        // Not configured, so untouched: the documentation-reserved constants stand.
        ->and($schema['properties']['site'])->toBe(['type' => 'string', 'format' => 'uri', 'example' => 'https://example.com'])
        ->and($schema['properties']['ref'])->toBe(['type' => 'string', 'format' => 'uuid', 'example' => '3fa85f64-5717-4562-b3fc-2c963f66afa6']);
});

it('falls back to the built-in sample where a later Laravel rule narrows the field past the configured one', function (): void {
    $policy = RepresentationPolicy::fromConfig(['examples' => ['formats' => ['email' => 'jane.doe+billing@example.com']]]);

    $result = convertLaravelRules(['contact' => 'required|email|max:20'], $policy);

    expect($result->schema['properties']['contact'])
        ->toBe(['type' => 'string', 'format' => 'email', 'maxLength' => 20, 'example' => 'user@example.com'])
        ->and(array_map(static fn ($d): string => $d->code, $result->diagnostics))->toBe(['config.format-sample-rejected'])
        ->and($result->diagnostics[0]->message)->toContain('field "contact"')
        ->and($result->diagnostics[0]->message)->toContain('maxLength');
});

it('emits byte-identical properties when no format sample is configured', function (array $configured): void {
    $fields = ['contact' => 'required|email', 'site' => 'required|url', 'ref' => 'required|uuid'];

    $bare = convertLaravelRules($fields);
    $configuredResult = convertLaravelRules($fields, RepresentationPolicy::fromConfig($configured));

    expect(json_encode($configuredResult->schema))->toBe(json_encode($bare->schema))
        ->and($configuredResult->diagnostics)->toBe([]);
})->with([
    'nothing configured' => [[]],
    'an empty formats map' => [['examples' => ['formats' => []]]],
    'a format none of these fields carries' => [['examples' => ['formats' => ['duration' => 'PT1H']]]],
]);

it('describes the field-reference forms of date-comparison and numeric-comparison rules', function (): void {
    // A field-reference comparison target is a runtime relationship: the numeric bound isn't a literal
    // so it can't be emitted, and a non-date target can't claim a `format`. Both degrade to a
    // description alongside the field's own type.
    $afterField = convertFieldRules([['string'], ['after', ['start_date']]])->schema['properties']['f'];
    expect($afterField)->toBe(['type' => 'string', 'description' => 'Must be a date after start_date.']);

    $gtField = convertFieldRules([['integer'], ['gt', ['minimum_qty']]])->schema['properties']['f'];
    expect($gtField)->toBe(['type' => 'integer', 'description' => 'Must be greater than minimum_qty.']);
});
