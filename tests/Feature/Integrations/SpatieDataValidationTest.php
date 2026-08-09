<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ValidatedData;

/**
 * Exhaustive coverage of the spatie validation-attribute → Laravel rule-token map ({@see
 * DataClassReflector::validationTokens()}): every supported attribute is recovered as the right
 * token, and an unsupported attribute degrades with the same permissive + info-diagnostic contract
 * as an unknown string rule. The token is then shown to drive the SHARED validation chain to the
 * expected schema effect.
 */
it('recovers the right Laravel rule token from every supported spatie validation attribute', function (string $property, string $expected): void {
    expect((new DataClassReflector)->validationTokens(ValidatedData::class, $property))->toBe([$expected]);
})->with([
    'Required' => ['required', 'required'],
    'Nullable' => ['nullable', 'nullable'],
    'Sometimes' => ['sometimes', 'sometimes'],
    'Present' => ['present', 'present'],
    'Prohibited' => ['prohibited', 'prohibited'],
    'Filled' => ['filled', 'filled'],
    'Email' => ['email', 'email'],
    'Url' => ['url', 'url'],
    'ActiveUrl' => ['activeUrl', 'active_url'],
    'Uuid' => ['uuid', 'uuid'],
    'Ulid' => ['ulid', 'ulid'],
    'Numeric' => ['numeric', 'numeric'],
    'IntegerType' => ['integer', 'integer'],
    'StringType' => ['string', 'string'],
    'BooleanType' => ['boolean', 'boolean'],
    'ArrayType' => ['arrayType', 'array'],
    'Alpha' => ['alpha', 'alpha'],
    'AlphaNumeric' => ['alphaNumeric', 'alpha_num'],
    'AlphaDash' => ['alphaDash', 'alpha_dash'],
    'Date' => ['date', 'date'],
    'Json' => ['json', 'json'],
    'Ip' => ['ip', 'ip'],
    'Max' => ['max', 'max:500'],
    'Min' => ['min', 'min:1'],
    'Size' => ['size', 'size:10'],
    'Between' => ['between', 'between:1,10'],
    'In' => ['in', 'in:draft,published'],
    'NotIn' => ['notIn', 'not_in:x,y'],
    'Regex' => ['regex', 'regex:/^[a-z]+$/'],
    'DateFormat' => ['dateFormat', 'date_format:Y-m-d'],
    'MaxDigits' => ['maxDigits', 'max_digits:5'],
    'MinDigits' => ['minDigits', 'min_digits:2'],
    'DigitsBetween' => ['digitsBetween', 'digits_between:1,5'],
    'StartsWith' => ['startsWith', 'starts_with:a'],
    'EndsWith' => ['endsWith', 'ends_with:z'],
    // Not in the reflector's map → snake-cased short name; recovery is independent of whether a
    // transformer then handles the token (the chain now maps `accepted` → const, but the token
    // recovery under test here is unchanged).
    'Accepted (not in the reflector map)' => ['accepted', 'accepted'],
]);

it('drives the supported tokens through the shared chain to the expected schema', function (): void {
    $result = buildValidatedSchema([
        new PropertyMetadata('email', ScalarT::string()),
        new PropertyMetadata('uuid', ScalarT::string()),
        new PropertyMetadata('url', ScalarT::string()),
        new PropertyMetadata('in', ScalarT::string()),
        new PropertyMetadata('max', ScalarT::string()),
        new PropertyMetadata('between', ScalarT::int()),
        new PropertyMetadata('regex', ScalarT::string()),
        new PropertyMetadata('arrayType', new ListT(ScalarT::string())),
        new PropertyMetadata('required', ScalarT::string()),
    ]);

    $props = $result->schema['properties'];
    expect($props['email'])->toBe(['type' => 'string', 'format' => 'email'])
        ->and($props['uuid'])->toBe(['type' => 'string', 'format' => 'uuid'])
        ->and($props['url'])->toBe(['type' => 'string', 'format' => 'uri'])
        ->and($props['in'])->toBe(['type' => 'string', 'enum' => ['draft', 'published']])
        ->and($props['max'])->toBe(['type' => 'string', 'maxLength' => 500])
        ->and($props['between'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 10])
        ->and($props['regex'])->toBe(['type' => 'string', 'pattern' => '^[a-z]+$'])
        // The request schema is built from rules (not the DType), so #[ArrayType] yields a bare array.
        ->and($props['arrayType'])->toBe(['type' => 'array']);

    // Non-nullable, non-optional properties are required.
    expect($result->schema['required'])->toContain('email', 'required');
});

it('degrades an unsupported spatie validation attribute like an unknown string rule', function (): void {
    // `active_url` has no transformer → the field stays a plain typed property and an info diagnostic
    // names it, identical to the unknown-string-rule contract (ValidationVocabularyTest).
    $result = buildValidatedSchema([new PropertyMetadata('activeUrl', ScalarT::string())]);

    expect($result->schema['properties']['activeUrl'])->toBe(['type' => 'string']);

    $unhandled = array_values(array_filter(
        $result->diagnostics,
        static fn ($d): bool => $d->code === 'validation.rule-unhandled' && str_contains($d->message, '"active_url"'),
    ));
    expect($unhandled)->toHaveCount(1);
});

it('makes a #[Nullable] property null-admitting', function (): void {
    $result = buildValidatedSchema([new PropertyMetadata('nullable', UnionT::of([ScalarT::string(), new NullT]))]);

    expect($result->schema['properties']['nullable']['type'])->toBe(['string', 'null']);
});

/**
 * Build the request schema for a subset of {@see ValidatedData}'s properties through the real
 * DataValidationRules recovery + the shared Laravel validation chain.
 *
 * @param  list<PropertyMetadata>  $properties
 */
function buildValidatedSchema(array $properties): ValidationSchema
{
    $metadata = new ClassMetadata(ValidatedData::class, $properties);
    $ruleSet = (new DataValidationRules)->build(ValidatedData::class, $metadata, new NullTypeEngine);
    $ordered = (new RuleOrdering)->order($ruleSet);

    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);

    return (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))->convert($ordered, $context);
}
