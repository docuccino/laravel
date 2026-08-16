<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\DataRequestExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AddressData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\BaseApiData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ImmutableNodeData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\InheritedMergeRulesData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MergedRulesController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MergedRulesData;
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
        // `#[ArrayType]` alone says only "an array"; the recovered `list<string>` synthesises the
        // `arrayType.*` item field Laravel would write by hand, so the items survive it.
        ->and($props['arrayType'])->toBe(['type' => 'array', 'items' => ['type' => 'string']]);

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

it('drops a #[Prohibited] property, and a prohibited NESTED Data object with its whole subtree', function (): void {
    // `#[Prohibited]` has to mean the same thing whatever the property's type. Reading it only off the
    // `prohibited` token would cover a scalar and miss a nested Data object, which takes the recursion
    // branch and appends no attribute rules at all — the field and every one of its children would
    // survive, still required. It is read where the FIELD is decided, so neither the object nor
    // `registered_address.city` is documented.
    $engine = new StubTypeEngine(classes: [
        AddressData::class => new ClassMetadata(AddressData::class, [
            new PropertyMetadata('city', ScalarT::string()),
            new PropertyMetadata('postcode', ScalarT::string()),
        ]),
    ]);
    $metadata = new ClassMetadata(ImmutableNodeData::class, [
        new PropertyMetadata('name', ScalarT::string()),
        new PropertyMetadata('slug', ScalarT::string()),
        new PropertyMetadata('registered_address', new ClassT(AddressData::class)),
    ]);
    $converter = new SchemaConverter(DefaultTypeMappers::all(), $engine, new ComponentRegistry, new RepresentationPolicy);

    $rules = (new DataValidationRules)->build(ImmutableNodeData::class, $metadata, $engine, null, $converter);

    expect(array_keys($rules->fields))->toBe(['name']);

    $schema = (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))
        ->convert((new RuleOrdering)->order((new RuleSetNormalizer)->normalize($rules)), $converter)->schema;

    expect($schema['properties'])->toBe(['name' => ['type' => 'string']])
        ->and($schema['required'])->toBe(['name']);
});

it('still lets a rules() override prohibit a field property inference documented', function (): void {
    // The other route to the same word, unchanged: a `prohibited` entry in the override reaches the rule
    // set's cross-field pass, which drops the field and everything under it.
    $engine = new StubTypeEngine(classes: [
        AddressData::class => new ClassMetadata(AddressData::class, [new PropertyMetadata('city', ScalarT::string())]),
    ]);
    $metadata = new ClassMetadata(ImmutableNodeData::class, [
        new PropertyMetadata('name', ScalarT::string()),
        new PropertyMetadata('registered_address', new ClassT(AddressData::class)),
    ]);
    $converter = new SchemaConverter(DefaultTypeMappers::all(), $engine, new ComponentRegistry, new RepresentationPolicy);
    $override = new RuleSet(['registered_address' => [ValidationRule::of('prohibited')]]);

    $rules = (new RuleSetNormalizer)->normalize(
        (new DataValidationRules)->build(ImmutableNodeData::class, $metadata, $engine, $override, $converter),
    );

    expect(array_keys($rules->fields))->toBe(['name']);
});

it('appends rather than replaces under #[MergeValidationRules]', function (string $fqcn): void {
    // Spatie's resolver `merge`s the override into what it inferred when the class carries the attribute,
    // instead of `add`ing over it. Documenting the replacement would drop the `#[Max(255)]` the API still
    // enforces — a body the docs call valid that the API rejects. PHP does not inherit class attributes,
    // but spatie's attribute collection walks the parent chain, so a base class carrying it merges for
    // every subclass too.
    $metadata = new ClassMetadata($fqcn, [new PropertyMetadata('name', ScalarT::string())]);
    $override = new RuleSet(['name' => [ValidationRule::of('min', ['3'])]]);

    $rules = (new DataValidationRules)->build($fqcn, $metadata, new NullTypeEngine, $override);

    expect(array_map(static fn (ValidationRule $rule): string => $rule->name, $rules->fields['name']))
        ->toBe(['required', 'string', 'max', 'min']);
})->with([
    'on the class itself' => [MergedRulesData::class],
    'on a base class' => [InheritedMergeRulesData::class],
]);

it('keys the fragment on the base class that answered the attribute', function (): void {
    // The merge decision is read off a file this class does not name, so an edit to the base — dropping
    // `#[MergeValidationRules]`, say — has to invalidate the fragment. A warm build reporting the
    // replacement the cold build no longer reports is the same wrong answer, served silently.
    $metadata = new ClassMetadata(InheritedMergeRulesData::class, [new PropertyMetadata('name', ScalarT::string())]);
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/merged-rules'),
        actionRef: new ActionRef('', MergedRulesController::class, 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(classes: [InheritedMergeRulesData::class => $metadata]),
        document: new DocumentConfig('default', []),
        typeMappers: DefaultTypeMappers::all(),
        ruleTransformers: ValidationIntegration::transformers(),
    );

    (new DataRequestExtension)->handle(new OperationDraft, $context);

    expect($context->dependencyFiles())
        ->toContain((string) (new ReflectionClass(BaseApiData::class))->getFileName())
        ->toContain((string) (new ReflectionClass(InheritedMergeRulesData::class))->getFileName());
});

/**
 * Build the request schema for a subset of {@see ValidatedData}'s properties through the real
 * DataValidationRules recovery + the shared normalise/order/convert sequence the extension runs.
 *
 * @param  list<PropertyMetadata>  $properties
 */
function buildValidatedSchema(array $properties): ValidationSchema
{
    $metadata = new ClassMetadata(ValidatedData::class, $properties);
    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);

    $ruleSet = (new DataValidationRules)->build(ValidatedData::class, $metadata, new NullTypeEngine, null, $context);
    $ordered = (new RuleOrdering)->order((new RuleSetNormalizer)->normalize($ruleSet));

    return (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))->convert($ordered, $context);
}
