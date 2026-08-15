<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\SpatieData\DataClassReflector;
use Docuccino\Laravel\Integrations\SpatieData\DataSchema;
use Docuccino\Laravel\Integrations\SpatieData\DataValidationRules;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AccountData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AccountStatus;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\AddressData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ContainerShapeData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\PinnedRuleData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\ProfileResource;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\RequestExclusionData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\SaveAnswersData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\TagData;
use Docuccino\Laravel\Tests\Fixtures\SpatieData\TimestampData;

/**
 * The harder spatie surfaces — class-level/mapper-class mapping, dates, enums, nested request recursion,
 * defaults, #[Computed]/#[Rule]/#[DataCollectionOf] and the BaseData trigger — over idiomatic fixtures.
 * The attribute facts come from real reflection; a stub engine scripts the property types, since the
 * analyzer's return-type work is proven out-of-process.
 */
function accountEngine(): StubTypeEngine
{
    return new StubTypeEngine(classes: [
        AccountData::class => new ClassMetadata(AccountData::class, [
            new PropertyMetadata('displayName', ScalarT::string()),
            new PropertyMetadata('createdAt', new ClassT(DateTimeImmutable::class)),
            new PropertyMetadata('status', new EnumT(AccountStatus::class, ['Active', 'Suspended'])),
            new PropertyMetadata('code', ScalarT::string()),
            new PropertyMetadata('address', new ClassT(AddressData::class)),
            new PropertyMetadata('tags', new ClassT(DataClassReflector::DATA_COLLECTION)),
            new PropertyMetadata('summary', ScalarT::string()),
            new PropertyMetadata('country', ScalarT::string()),
        ]),
        AddressData::class => new ClassMetadata(AddressData::class, [
            new PropertyMetadata('city', ScalarT::string()),
            new PropertyMetadata('postcode', ScalarT::string()),
        ]),
        TagData::class => new ClassMetadata(TagData::class, [
            new PropertyMetadata('label', ScalarT::string()),
        ]),
    ]);
}

it('applies every built-in name mapper, degrading an unknown mapper', function (?string $mapper, string $expected): void {
    expect(DataClassReflector::mapWithMapper((string) $mapper, 'displayName'))->toBe($mapper === null ? null : $expected);
})->with([
    'snake' => ['Spatie\\LaravelData\\Mappers\\SnakeCaseMapper', 'display_name'],
    'camel' => ['Spatie\\LaravelData\\Mappers\\CamelCaseMapper', 'displayName'],
    'studly' => ['Spatie\\LaravelData\\Mappers\\StudlyCaseMapper', 'DisplayName'],
    'lower' => ['Spatie\\LaravelData\\Mappers\\LowerCaseMapper', 'displayname'],
    'upper' => ['Spatie\\LaravelData\\Mappers\\UpperCaseMapper', 'DISPLAYNAME'],
]);

it('returns null for an unrecognised mapper class or a non-class value', function (): void {
    expect(DataClassReflector::mapWithMapper('App\\Mappers\\CustomMapper', 'displayName'))->toBeNull()
        ->and(DataClassReflector::mapWithMapper('not_a_class', 'displayName'))->toBeNull();
});

it('renames every key through a class-level mapper (input and output)', function (): void {
    $reflector = new DataClassReflector;

    expect($reflector->outputName(AccountData::class, 'displayName'))->toBe('display_name')
        ->and($reflector->inputName(AccountData::class, 'createdAt'))->toBe('created_at')
        ->and($reflector->outputName(AccountData::class, 'code'))->toBe('code');
});

it('recognises a spatie Resource via the BaseData interface', function (): void {
    expect(DataClassReflector::isData(ProfileResource::class))->toBeTrue();
});

it('reflects the new attribute facts off the real class', function (): void {
    $reflector = new DataClassReflector;

    expect($reflector->isExcludedFromRequest(AccountData::class, 'summary'))->toBeTrue()
        ->and($reflector->isExcludedFromRequest(AccountData::class, 'displayName'))->toBeFalse()
        ->and($reflector->propertyDefault(AccountData::class, 'country'))->toBe(['hasDefault' => true, 'value' => 'GB'])
        ->and($reflector->propertyDefault(AccountData::class, 'displayName')['hasDefault'])->toBeFalse()
        ->and($reflector->dataCollectionOf(AccountData::class, 'tags'))->toBe(TagData::class)
        ->and($reflector->validationTokens(AccountData::class, 'code'))->toBe(['max:5'])
        ->and($reflector->validationTokens(AccountData::class, 'status'))->toBe(['in:active,suspended']);
});

it('separates having a default from having a documentable one', function (): void {
    // The two questions the one call answers: a defaulted property is never REQUIRED, whatever the
    // default is, while only a scalar default is a `default` keyword. Conflating them made
    // `$touched_fields = []` a required request property.
    $reflector = new DataClassReflector;

    expect($reflector->propertyDefault(SaveAnswersData::class, 'touched_fields'))
        ->toBe(['hasDefault' => true, 'value' => null])
        ->and($reflector->propertyDefault(ContainerShapeData::class, 'extras')['hasDefault'])->toBeTrue()
        ->and($reflector->propertyDefault(ContainerShapeData::class, 'extras')['value'])->toBeNull()
        // A property with no default at all is unchanged.
        ->and($reflector->propertyDefault(SaveAnswersData::class, 'zone_key'))
        ->toBe(['hasDefault' => false, 'value' => null]);
});

it('silently drops an object-valued #[Rule(new …)] but recovers the string form', function (): void {
    $reflector = new DataClassReflector;

    // A custom rule object can't be recovered statically, so no tokens — the field keeps its type.
    expect($reflector->validationTokens(PinnedRuleData::class, 'label'))->toBe([])
        // The string escape-hatch form still lands.
        ->and($reflector->validationTokens(PinnedRuleData::class, 'code'))->toBe(['max:5']);
});

it('documents a WithCast DateTimeInterfaceCast format:U property as an integer timestamp', function (): void {
    $components = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [
        TimestampData::class => new ClassMetadata(TimestampData::class, [
            new PropertyMetadata('expiresAt', new ClassT('DateTimeImmutable')),
            new PropertyMetadata('createdAt', new ClassT('DateTimeImmutable')),
        ]),
    ]);
    (new SchemaConverter([new DataSchema, ...DefaultTypeMappers::all()], $engine, $components))->toSchema(new ClassT(TimestampData::class));

    $properties = $components->schemas()['TimestampData']['properties'];

    // A `format: 'U'` cast becomes an integer (Unix seconds); a plain datetime stays a date-time string.
    expect($properties['expiresAt'])->toBe(['type' => 'integer', 'description' => 'Unix timestamp (seconds).'])
        ->and($properties['createdAt'])->toBe(['type' => 'string', 'format' => 'date-time']);

    // The reflector reads the format straight off the attribute.
    expect((new DataClassReflector)->dateTimeCastFormat(TimestampData::class, 'expiresAt'))->toBe('U')
        ->and((new DataClassReflector)->dateTimeCastFormat(TimestampData::class, 'createdAt'))->toBeNull();
});

it('excludes route-parameter and explicitly request-hidden properties, but not output-#[Hidden] ones', function (): void {
    $reflector = new DataClassReflector;

    // A plain body field is sendable.
    expect($reflector->isExcludedFromRequest(RequestExclusionData::class, 'name'))->toBeFalse()
        // #[FromRouteParameter] comes from the binding, not the body.
        ->and($reflector->isExcludedFromRequest(RequestExclusionData::class, 'id'))->toBeTrue()
        // #[HiddenFromRequest] explicitly drops the field from the request body.
        ->and($reflector->isExcludedFromRequest(RequestExclusionData::class, 'internalToken'))->toBeTrue()
        // #[Hidden] hides from output only; the field stays sendable. Surfacing it is the leakage lint's
        // job, so #[Hidden] mustn't quietly remove it from the request.
        ->and($reflector->isExcludedFromRequest(RequestExclusionData::class, 'secret'))->toBeFalse();
});

it('recovers request rules: enum values, defaults, computed exclusion, and nested recursion', function (): void {
    $engine = accountEngine();
    $ruleSet = (new DataValidationRules)->build(AccountData::class, $engine->classMetadata(new ClassRef(AccountData::class)), $engine);

    $names = static fn (string $field): array => array_map(static fn ($r): string => $r->name, $ruleSet->fields[$field] ?? []);

    // #[Computed] summary is excluded entirely.
    expect($ruleSet->fields)->not->toHaveKey('summary');
    // Class-level snake_case mapper renames the input keys.
    expect($ruleSet->fields)->toHaveKeys(['display_name', 'created_at', 'status', 'code', 'country']);
    // An enum property becomes an `enum` rule carrying the backing values.
    $statusEnum = collect($ruleSet->fields['status'])->firstWhere('name', 'enum');
    expect($statusEnum?->parameters)->toBe(['active', 'suspended']);
    // Defaulted `country` is optional (sometimes), not required.
    expect($names('country'))->toContain('sometimes')->not->toContain('required');
    // #[Rule('max:5')] escape hatch parsed as a real max rule.
    $codeMax = collect($ruleSet->fields['code'])->firstWhere('name', 'max');
    expect($codeMax?->parameter(0))->toBe('5');
    // Nested AddressData recurses into dotted rules.
    expect($ruleSet->fields)->toHaveKeys(['address.city', 'address.postcode'])
        ->and($names('address.city'))->toContain('required')->toContain('string');
    // #[DataCollectionOf(TagData)] recurses under the wildcard.
    expect($ruleSet->fields)->toHaveKey('tags.*.label');
});

it('renders dates, enums, defaults, and collection items in the output schema', function (): void {
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new DataSchema(dateFormat: 'Y-m-d\TH:i:sP'), ...DefaultTypeMappers::all()], accountEngine(), $components);
    $converter->toSchema(new ClassT(AccountData::class));

    $account = $components->schemas()['AccountData'];
    $props = $account['properties'];

    // DateTimeInterface becomes a formatted string, not a bare object; DATE_ATOM is a date-time.
    expect($props['created_at'])->toBe(['type' => 'string', 'format' => 'date-time']);
    // #[DataCollectionOf] becomes an array of the item schema.
    expect($props['tags']['type'])->toBe('array')
        ->and($props['tags']['items'])->toHaveKey('$ref');
    // Constructor default surfaced as the schema default.
    expect($props['country']['default'] ?? null)->toBe('GB');
    // #[Computed] summary is output; the enum is a hoisted enum schema $ref or inline enum.
    expect($account['properties'])->toHaveKey('status');
});

it('maps a date-only data.date_format to the date format', function (): void {
    $components = new ComponentRegistry;
    $converter = new SchemaConverter([new DataSchema(dateFormat: 'Y-m-d'), ...DefaultTypeMappers::all()], accountEngine(), $components);
    $converter->toSchema(new ClassT(AccountData::class));

    expect($components->schemas()['AccountData']['properties']['created_at'])->toBe(['type' => 'string', 'format' => 'date']);
});
