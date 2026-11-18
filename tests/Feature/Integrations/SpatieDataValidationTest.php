<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\SchemaNotes;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\FormRequest\ConstValueToRules;
use Docuccino\Laravel\Integrations\FormRequest\ShapeToRuleSet;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\DataRequestExtension;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Integrations\Support\RuleDialect;
use Docuccino\Laravel\Integrations\Validation\LiveRuleValue;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AddressData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AttributeKeywordData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\BaseApiData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\CustomMappedController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\CustomMappedData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\EnumCaseChoicesData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ImmutableNodeData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\InheritedMergeRulesData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MergedRulesController;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\MergedRulesData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ReversedNameMapper;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RuleObjectChoicesData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ValidatedData;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationRuleParser;
use Spatie\LaravelData\Support\Validation\ValidationPath;
use Workbench\App\Enums\WidgetStatus;

/**
 * Coverage of the spatie validation-attribute → Laravel rule-token reading ({@see
 * DataClassReflector::attributeRules()}): attributes are recovered as the rule laravel-data writes
 * for them, and one the vocabulary has no reader for degrades with the same permissive + info-diagnostic contract
 * as an unknown string rule. The token is then shown to drive the SHARED validation chain to the
 * expected schema effect.
 */
it('recovers the right Laravel rule token from every supported spatie validation attribute', function (string $property, string $expected): void {
    expect((new DataClassReflector)->attributeRules(ValidatedData::class, $property)[0])->toBe([$expected]);
})->with([
    'Required' => ['required', 'required'],
    'Nullable' => ['nullable', 'nullable'],
    'Sometimes' => ['sometimes', 'sometimes'],
    'Present' => ['present', 'present'],
    'Prohibited' => ['prohibited', 'prohibited'],
    'Filled' => ['filled', 'filled'],
    // laravel-data checks an address written with no mode against RFC 5322, and writes so.
    'Email' => ['email', 'email:rfc'],
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
    'IP' => ['ip', 'ip'],
    'Max' => ['max', 'max:500'],
    'Min' => ['min', 'min:1'],
    'Size' => ['size', 'size:10'],
    'Between' => ['between', 'between:1,10'],
    'Regex' => ['regex', 'regex:/^[a-z]+$/'],
    'DateFormat' => ['dateFormat', 'date_format:Y-m-d'],
    'MaxDigits' => ['maxDigits', 'max_digits:5'],
    'MinDigits' => ['minDigits', 'min_digits:2'],
    'DigitsBetween' => ['digitsBetween', 'digits_between:1,5'],
    'StartsWith' => ['startsWith', 'starts_with:a'],
    'EndsWith' => ['endsWith', 'ends_with:z'],
    'Accepted' => ['accepted', 'accepted'],
]);

it('recovers the rule an attribute names itself, not its class name', function (string $property, string $expected): void {
    // laravel-data writes each attribute's rule from its own keyword(), so `#[GreaterThan(5)]` is `gt:5`; its class
    // name snake-cased (`greater_than`) is a rule Laravel has never heard of.
    expect((new DataClassReflector)->attributeRules(AttributeKeywordData::class, $property)[0])->toBe([$expected]);
})->with([
    'ListType' => ['items', 'list'],
    'GreaterThan' => ['above', 'gt:5'],
    'GreaterThanOrEqualTo' => ['atLeast', 'gte:5'],
    'LessThan' => ['below', 'lt:10'],
    'LessThanOrEqualTo' => ['atMost', 'lte:10'],
    'IPv4' => ['v4', 'ipv4'],
    'IPv6' => ['v6', 'ipv6'],
    'MimeTypes' => ['avatar', 'mimetypes:image/png'],
    // Its parameters come in the order the attribute declares them, not the order they were named in.
    'Between, named out of order' => ['count', 'between:1,10'],
]);

it('reads an #[In] or #[NotIn] as the rule laravel-data builds from it, as Laravel parses that rule', function (string $fqcn, string $property, string $name, array $parameters, bool $case): void {
    $reflected = (new ReflectionProperty($fqcn, $property))->getAttributes()[0];

    // spatie/laravel-data takes a case in these attributes from 4.21; before it, PHP refuses one, so no
    // application on that version can write what this reads.
    $takes = (new ReflectionParameter([$reflected->getName(), '__construct'], 0))->getType();
    $types = $takes instanceof ReflectionUnionType ? $takes->getTypes() : [$takes];

    if ($case && ! in_array(UnitEnum::class, array_map(static fn ($type): string => $type instanceof ReflectionNamedType ? $type->getName() : '', $types), true)) {
        $this->markTestSkipped('The installed spatie/laravel-data takes no enum case in #['.class_basename($reflected->getName()).'].');
    }

    // Spatie hands the values to Laravel's In/NotIn, which writes each as PHP makes it a string: a backed case
    // as its backing value, a pure one as its name, `true` as "1" and `false` as "". Laravel's parser then reads
    // the list as CSV, so a quoted value keeps its comma. What it reads off the rule Spatie builds is owed.
    $attribute = $reflected->newInstance();
    [$parsed, $owed] = ValidationRuleParser::parse((string) $attribute->getRule(ValidationPath::create()));

    $read = [];
    foreach ((new DataClassReflector)->attributeRules($fqcn, $property)[1] as $object) {
        $read = [...$read, ...(new ConstValueToRules)->fold(LiveRuleValue::of($object))];
    }

    expect((new DataClassReflector)->attributeRules($fqcn, $property)[0])->toBe([])
        ->and(array_map(static fn (ValidationRule $rule): array => [$rule->name, $rule->parameters], $read))->toBe([[$name, $parameters]])
        ->and([Str::snake($parsed), $owed])->toBe([$name, $parameters]);
})->with([
    'values' => [ValidatedData::class, 'in', 'in', ['draft', 'published'], false],
    'excluded values' => [ValidatedData::class, 'notIn', 'not_in', ['x', 'y'], false],
    'a case beside a value' => [EnumCaseChoicesData::class, 'schedule', 'in', ['draft', 'scheduled'], true],
    'int-backed cases' => [EnumCaseChoicesData::class, 'priority', 'in', ['1', '10'], true],
    'a pure case' => [EnumCaseChoicesData::class, 'channel', 'in', ['Email'], true],
    'an excluded case' => [EnumCaseChoicesData::class, 'phase', 'not_in', ['archived'], true],
    'both booleans' => [RuleObjectChoicesData::class, 'consent', 'in', ['1', ''], false],
    'false excluded' => [RuleObjectChoicesData::class, 'agreed', 'not_in', [''], false],
    'a value holding a comma' => [RuleObjectChoicesData::class, 'pair', 'in', ['a,b', 'c'], false],
]);

it('records the enum a validation attribute quotes values from, so editing it re-documents the rule', function (): void {
    $metadata = new ClassMetadata(EnumCaseChoicesData::class, [
        new PropertyMetadata('schedule', ScalarT::string()),
        new PropertyMetadata('status', ScalarT::string()),
    ]);

    $rules = new DataValidationRules;
    $rules->build(EnumCaseChoicesData::class, $metadata, new NullTypeEngine);

    expect($rules->dependencyFiles())->toContain((string) (new ReflectionEnum(WidgetStatus::class))->getFileName())
        ->and((new DataClassReflector)->validationEnumFiles(EnumCaseChoicesData::class, 'status'))
        ->toBe([(string) (new ReflectionEnum(WidgetStatus::class))->getFileName()]);
});

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
    // Each carries the example its own rule earns — the format's sample, the enum's first member,
    // the bound itself (ValidationVocabularyTest owns the whole table).
    expect($props['email'])->toBe(['type' => 'string', 'format' => 'email', 'example' => 'user@example.com'])
        ->and($props['uuid'])->toBe(['type' => 'string', 'format' => 'uuid', 'example' => '3fa85f64-5717-4562-b3fc-2c963f66afa6'])
        ->and($props['url'])->toBe(['type' => 'string', 'format' => 'uri', 'example' => 'https://example.com'])
        ->and($props['in'])->toBe(['type' => 'string', 'enum' => ['draft', 'published'], 'x-enum-varnames' => ['Draft', 'Published'], 'x-enumNames' => ['Draft', 'Published'], 'example' => 'draft'])
        ->and($props['max'])->toBe(['type' => 'string', 'maxLength' => 500, 'example' => 'example'])
        ->and($props['between'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'example' => 1])
        ->and($props['regex'])->toBe(['type' => 'string', 'pattern' => '^[a-z]+\\n?$', 'example' => 'example'])
        // `#[ArrayType]` alone says only "an array"; the recovered `list<string>` synthesises the
        // `arrayType.*` item field Laravel would write by hand, so the items survive it.
        ->and($props['arrayType'])->toBe(['type' => 'array', 'items' => ['type' => 'string']]);

    // Non-nullable, non-optional properties are required.
    expect($result->schema['required'])->toContain('email', 'required');
});

it('reads a spatie validation attribute as the framework rule it validates with', function (): void {
    // `#[ActiveUrl]` validates with the framework's `active_url`, which the chain reads as every framework
    // rule is read: a note beside the string type, and nothing reported unhandled.
    $result = buildValidatedSchema([new PropertyMetadata('activeUrl', ScalarT::string())]);

    expect(SchemaNotes::rendered($result->schema)['properties']['activeUrl'])->toBe(['type' => 'string', 'description' => 'Must be a URL whose host resolves in DNS.'])
        ->and(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'validation.rule-unhandled'))->toBe([]);
});

it('reads an attribute that builds a rule object as the rules Laravel validates with that object', function (string $attribute, string $source, ClassT|ScalarT $type, array $schema, array $unread): void {
    $carrier = attributeCarrier('Spatie\\LaravelData\\Attributes\\Validation\\'.$attribute, $source);
    $context = new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, new RepresentationPolicy);

    $rules = new DataValidationRules;
    $ruleSet = $rules->build($carrier, new ClassMetadata($carrier, [new PropertyMetadata('value', $type)]), new NullTypeEngine, null, $context);
    $converted = (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))->convert((new RuleOrdering)->order((new RuleSetNormalizer)->normalize($ruleSet)), $context);

    expect(array_diff_key(SchemaNotes::rendered($converted->schema)['properties']['value'], ['example' => true]))->toBe($schema)
        ->and(array_map(static fn (array $note): string => $note['rule'], $rules->unreadRuleObjects()))->toBe($unread)
        ->and(array_filter($converted->diagnostics, static fn ($d): bool => $d->code === 'validation.rule-unhandled'))->toBe([]);
})->with([
    // Laravel casts a Dimensions to `dimensions:min_width=10,max_height=50`, the bounds it checks an image against.
    'dimensions by name' => ['Dimensions', 'maxHeight: 50, minWidth: 10', new ClassT('Illuminate\\Http\\UploadedFile'), ['type' => 'string', 'format' => 'binary', 'description' => 'Image dimensions: min_width=10, max_height=50.'], []],
    'dimensions by position' => ['Dimensions', '10, 20', new ClassT('Illuminate\\Http\\UploadedFile'), ['type' => 'string', 'format' => 'binary', 'description' => 'Image dimensions: min_width=10, min_height=20.'], []],
    'dimensions handed to #[Rule]' => ['Rule', 'new \Illuminate\Validation\Rules\Dimensions(["min_width" => 10])', new ClassT('Illuminate\\Http\\UploadedFile'), ['type' => 'string', 'format' => 'binary', 'description' => 'Image dimensions: min_width=10.'], []],
    // A password is first validated as `string|min:10`; a letters check then runs on it, which no schema states.
    'a password' => ['Password', 'min: 10', ScalarT::string(), ['type' => 'string', 'minLength' => 10], []],
    'a password by position' => ['Password', '10, true', ScalarT::string(), ['type' => 'string', 'minLength' => 10], ['new Illuminate\\Validation\\Rules\\Password']],
    'a password at its default' => ['Password', '', ScalarT::string(), ['type' => 'string', 'minLength' => 12], []],
    // A table and a column decide nothing the document states.
    'a row that must exist' => ['Exists', 'column: "email", table: "users"', ScalarT::string(), ['type' => 'string'], []],
    'a value that must be unique' => ['Unique', '"users", ignore: "5"', ScalarT::string(), ['type' => 'string'], []],
]);

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

it('reads the rule strings of a rules() array as its reader splits them, on the engine\'s literal path too', function (RuleDialect $dialect, array $overridden, array $pattern): void {
    // A Data class's rules() is read by laravel-data, which splits a string in a list at its pipes and keeps a
    // string holding a regex whole; a Form Request's by Laravel, which does neither (each reference is checked
    // in RuleParsingTest). The engine's constant array shape owes the same answer as the traced array.
    $shape = new ArrayShapeT([
        new ArrayShapeField('overridden', new ArrayShapeT([new ArrayShapeField(0, new LiteralT('string|max:3'))])),
        new ArrayShapeField('overriddenPattern', new LiteralT('regex:/^(ab|cd)$/')),
    ]);

    $fields = (new ShapeToRuleSet(dialect: $dialect))->convert($shape)->fields;
    $read = static fn (string $field): array => array_map(static fn (ValidationRule $rule): array => [$rule->name, $rule->parameters], $fields[$field]);

    expect($read('overridden'))->toBe($overridden)
        ->and($read('overriddenPattern'))->toBe($pattern);
})->with([
    'laravel-data' => [RuleDialect::LaravelData, [['string', []], ['max', ['3']]], [['regex', ['/^(ab|cd)$/']]]],
    'Laravel' => [RuleDialect::Laravel, [['string|max', ['3']]], [['regex', ['/^(ab']], ['cd)$/', []]]],
]);

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
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );

    (new DataRequestExtension)->handle(new OperationDraft, $context);

    expect($context->dependencyFiles())
        ->toContain((string) (new ReflectionClass(BaseApiData::class))->getFileName())
        ->toContain((string) (new ReflectionClass(InheritedMergeRulesData::class))->getFileName());
});

it('says so when a Data class is renamed by a mapper it cannot read', function (): void {
    // A mapper of the application's own is arbitrary code, so the keys the endpoint really accepts are
    // unknowable — the properties are documented under their own names and the reader is told the
    // renaming was not applied, rather than being handed key names the server will reject.
    $metadata = new ClassMetadata(CustomMappedData::class, [new PropertyMetadata('displayName', ScalarT::string())]);
    $context = new RouteContext(
        route: new RouteDescriptor(['POST'], 'api/custom-mapped'),
        actionRef: new ActionRef('', CustomMappedController::class, 'store'),
        attributes: new AttributeSet,
        engine: new StubTypeEngine(classes: [CustomMappedData::class => $metadata]),
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(
            typeToSchema: DefaultTypeMappers::all(),
            ruleTransformers: ValidationIntegration::transformers(),
        ),
    );

    (new DataRequestExtension)->handle(new OperationDraft, $context);

    $reported = array_values(array_filter(
        $context->components->diagnostics(),
        static fn ($d): bool => $d->code === 'spatie-data.unknown-mapper',
    ));

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->message)->toContain(ReversedNameMapper::class)
        ->and($reported[0]->routeSignature)->toBe('POST api/custom-mapped');
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
