<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Concerns\ValidatesAttributes;

/**
 * Every rule the INSTALLED framework validates is one the chain reads. A rule nothing reads raised
 * `validation.rule-unhandled`, telling the author to register a transformer for a rule they did not write —
 * so the set of read rules is a hand-kept list, and this reads the source of truth it must cover: each
 * `validate*` method Laravel's validator dispatches a rule name to.
 */
it('reads every rule the installed framework validates', function (): void {
    $rules = [];
    foreach ((new ReflectionClass(ValidatesAttributes::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (str_starts_with($method->getName(), 'validate') && $method->getName() !== 'validate') {
            $rules[] = Str::snake(substr($method->getName(), strlen('validate')));
        }
    }

    $unread = array_values(array_filter($rules, static function (string $name): bool {
        foreach (ValidationIntegration::transformers() as $transformer) {
            if ($transformer->supports(ValidationRule::of($name, ['a', 'b']))) {
                return false;
            }
        }

        return true;
    }));

    // A scan that stopped seeing the validator's methods would pass on nothing.
    expect(count($rules))->toBeGreaterThan(100)
        ->and($unread)->toBe([]);
});

/**
 * What each rule the chain now reads publishes. Where no keyword states the constraint truly the field keeps
 * the shape the rest of its rules give it and carries the constraint as a note — a person reads the note, and
 * nothing narrower than the server reaches a client. Where a keyword does state it exactly, the keyword.
 *
 * @param  list<string>  $rules
 * @param  array<string, mixed>  $expected  keywords the field's schema must carry
 */
it('publishes what each newly read rule states', function (array $rules, array $expected): void {
    // Split as Laravel splits a rule string: name before the first colon, parameters on commas — except a
    // regex, whose one parameter may hold a comma of its own.
    $parse = static function (string $rule): ValidationRule {
        [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, null);

        return ValidationRule::of($name, $parameters === null ? [] : (str_ends_with($name, 'regex') ? [$parameters] : explode(',', $parameters)));
    };
    $schema = validationSchema(new RuleSet(['f' => array_map($parse, $rules)]), schemaConverter());

    foreach ($expected as $keyword => $value) {
        expect($schema['properties']['f'][$keyword] ?? null)->toBe($value);
    }
})->with([
    // An escaped literal states the negation exactly, so it is a keyword.
    'doesnt_start_with, one affix' => [['string', 'doesnt_start_with:tmp_'], ['not' => ['type' => 'string', 'pattern' => '^tmp_']]],
    'doesnt_end_with, one affix' => [['string', 'doesnt_end_with:.bak'], ['not' => ['type' => 'string', 'pattern' => '\\.bak$']]],
    // Only refusing, so an untyped field stays untyped: Laravel takes a number there.
    'doesnt_start_with on an untyped field' => [['doesnt_start_with:tmp_'], ['type' => null, 'not' => ['type' => 'string', 'pattern' => '^tmp_']]],
    'doesnt_start_with, several affixes' => [['string', 'doesnt_start_with:tmp_,old_'], ['description' => 'Must not start with any of: tmp_, old_.']],
    'doesnt_end_with on a field already negated' => [['string', 'doesnt_start_with:tmp_', 'doesnt_end_with:.bak'], ['not' => ['type' => 'string', 'pattern' => '^tmp_'], 'description' => 'Must not end with any of: .bak.']],
    // The URL's host is resolved at run time; its shape is a string and nothing narrower.
    'active_url' => [['active_url'], ['type' => 'string', 'description' => 'Must be a URL whose host resolves in DNS.']],
    'ascii' => [['string', 'ascii'], ['type' => 'string', 'description' => 'Must contain only ASCII characters.']],
    'lowercase' => [['string', 'lowercase'], ['description' => 'Must be lowercase.']],
    'uppercase' => [['string', 'uppercase'], ['description' => 'Must be uppercase.']],
    'encoding' => [['string', 'encoding:UTF-8'], ['description' => 'Must be valid UTF-8.']],
    'not_regex, comma and all' => [['string', 'not_regex:/^a{1,3}$/'], ['description' => 'Must not match /^a{1,3}$/.']],
    'same' => [['string', 'same:password'], ['description' => 'Must match password.']],
    'different, one field' => [['string', 'different:old_email'], ['description' => 'Must differ from old_email.']],
    'different, several' => [['string', 'different:a,b'], ['description' => 'Must differ from each of a, b.']],
    'in_array' => [['string', 'in_array:allowed.*'], ['description' => 'Must be one of the values sent in allowed.']],
    'in_array_keys' => [['array', 'in_array_keys:en,fr'], ['description' => 'Must contain at least one of the keys: en, fr.']],
    'array_keys' => [['array', 'array_keys:en,fr'], ['description' => 'May contain only the keys: en, fr.']],
    'contains' => [['array', 'contains:admin,editor'], ['description' => 'Must contain each of: admin, editor.']],
    'doesnt_contain' => [['array', 'doesnt_contain:root'], ['description' => 'Must contain none of: root.']],
    'date_equals' => [['date', 'date_equals:today'], ['description' => 'Must be the same date as today.']],
    'required_if_accepted' => [['string', 'required_if_accepted:terms'], ['description' => 'Required when terms is accepted.']],
    'required_if_declined' => [['string', 'required_if_declined:terms'], ['description' => 'Required when terms is declined.']],
    'present_if' => [['string', 'present_if:kind,company'], ['description' => 'Must be sent, even empty, when kind is company.']],
    'present_unless' => [['string', 'present_unless:kind,person'], ['description' => 'Must be sent, even empty, unless kind is person.']],
    'present_with' => [['string', 'present_with:a,b'], ['description' => 'Must be sent, even empty, when any of a, b is present.']],
    'present_with_all' => [['string', 'present_with_all:a,b'], ['description' => 'Must be sent, even empty, when a, b are all present.']],
    'prohibited_if_accepted' => [['string', 'prohibited_if_accepted:terms'], ['description' => 'Must not be sent when terms is accepted.']],
    'prohibited_if_declined' => [['string', 'prohibited_if_declined:terms'], ['description' => 'Must not be sent when terms is declined.']],
    'missing_if' => [['string', 'missing_if:kind,person'], ['description' => 'Must not be sent when kind is person.']],
    'missing_unless' => [['string', 'missing_unless:kind,company'], ['description' => 'Must not be sent unless kind is company.']],
    'missing_with' => [['string', 'missing_with:a'], ['description' => 'Must not be sent when a is present.']],
    'missing_with_all' => [['string', 'missing_with_all:a,b'], ['description' => 'Must not be sent when a, b are all present.']],
]);

/**
 * `required_array_keys:x,y` checks that each key is in the array, whatever it holds — the JSON Schema
 * `required` on the object, and nothing about the values. A null crop still passes: the rule does not run
 * on a value `nullable` admitted, and `required` constrains only an object.
 */
it('publishes the keys required_array_keys names as required, and nothing about their values', function (): void {
    $schema = validationSchema(new RuleSet([
        'crop' => [ValidationRule::of('nullable'), ValidationRule::of('array'), ValidationRule::of('required_array_keys', ['x', 'y'])],
        'crop.x' => [ValidationRule::of('numeric')],
    ]), schemaConverter());

    expect($schema['properties']['crop']['type'])->toBe(['object', 'null'])
        ->and($schema['properties']['crop']['required'])->toBe(['x', 'y'])
        ->and($schema['properties']['crop']['properties']['x'])->toBe(['type' => 'number'])
        ->and($schema['properties']['crop']['properties'])->toHaveKey('y');

    // Read as a rule the chain consumes, so nothing reports it unhandled.
    $diagnostics = (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))
        ->convert((new RuleOrdering)->order((new RuleSetNormalizer)->normalize(new RuleSet(['crop' => [ValidationRule::of('array'), ValidationRule::of('required_array_keys', ['x'])]]))), schemaConverter())
        ->diagnostics;
    expect(array_map(static fn ($d): string => $d->code, $diagnostics))->not->toContain('validation.rule-unhandled');
});

/**
 * The reading is stated independently of the code that makes it: for every body, Laravel's own validator
 * decides `required_array_keys` exactly as a JSON Schema validator decides the schema the document
 * publishes for it. A rewrite onto Laravel's `present` would NOT agree — Laravel runs `present` on
 * `crop.x` even where `crop` is null or absent — which is why the agreement asked for is the published
 * schema's, where `required` constrains only an object.
 */
it('publishes required_array_keys as a schema accepting exactly the bodies Laravel accepts', function (array $body): void {
    $rules = ['crop' => ['nullable', 'array', 'required_array_keys:x,y']];
    $schema = validationSchema(new RuleSet(['crop' => [
        ValidationRule::of('nullable'), ValidationRule::of('array'), ValidationRule::of('required_array_keys', ['x', 'y']),
    ]]), schemaConverter());

    // An unconstrained member is `[]` in PHP and `{}` in the document; the validator reads the document's.
    $asPublished = static function (mixed $node) use (&$asPublished): mixed {
        if (! is_array($node)) {
            return $node;
        }
        $out = array_map($asPublished, $node);
        foreach (is_array($out['properties'] ?? null) ? $out['properties'] : [] as $name => $member) {
            $out['properties'][$name] = $member === [] ? new stdClass : $member;
        }

        return $out;
    };

    $published = (new Opis\JsonSchema\Validator)->validate(json_decode((string) json_encode((object) $body)), json_decode((string) json_encode($asPublished($schema))))->isValid();

    expect($published)->toBe(Validator::make($body, $rules)->passes());
})->with([
    'both keys' => [['crop' => ['x' => 0.1, 'y' => 0.2]]],
    'both keys, one null' => [['crop' => ['x' => null, 'y' => 0.2]]],
    'both keys, one empty' => [['crop' => ['x' => '', 'y' => 0.2]]],
    'one key' => [['crop' => ['x' => 0.1]]],
    'no keys' => [['crop' => (object) []]],
    'extra key' => [['crop' => ['x' => 1, 'y' => 1, 'z' => 1]]],
    'null crop' => [['crop' => null]],
    'no crop' => [[]],
]);

it('drops a field that must be missing, and everything under it', function (): void {
    $normalized = (new RuleSetNormalizer)->normalize(new RuleSet([
        'name' => [ValidationRule::of('string')],
        'legacy' => [ValidationRule::of('missing')],
        'legacy.id' => [ValidationRule::of('string')],
    ]));

    expect(array_keys($normalized->fields))->toBe(['name']);
});
