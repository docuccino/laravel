<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Ordering\ExtensionSorter;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\ValidationIntegration;
use Workbench\App\Enums\WidgetStatus;

/**
 * A request body typed by a validation rule and a response body typed by the same enum are ONE
 * component. The rule and the type chain are different producers reaching the same class, and a
 * generated client should get one named type for it, not one per direction.
 */

/**
 * One field's rules and one converted DType through the SAME converter, so the registry either holds
 * one component for the enum or holds two — which is the whole question.
 *
 * @param  list<array{0: string, 1?: list<string>, 2?: string}>  $rules
 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
 */
function convertRulesBesideType(array $rules, ?EnumT $type = null, RepresentationPolicy $policy = new RepresentationPolicy): array
{
    $registry = new ComponentRegistry;
    // SORTED, as the pipeline builds them: the reflection-rich enum mapper only supersedes the
    // case-names-only one by running earlier, so an unordered chain silently tests the wrong mapper.
    $converter = new SchemaConverter(
        // EnumSchema is registered by the adapter, not by core's mapper set, and only supersedes the
        // case-names-only mapper by running earlier — so the chain is built the way the pipeline
        // builds it, or this tests a mapper the product never reaches.
        (new ExtensionSorter)->sort([new EnumSchema, ...DefaultTypeMappers::all()]),
        new NullTypeEngine,
        $registry,
        $policy,
    );

    $ruleObjects = array_map(
        static fn (array $r): ValidationRule => ValidationRule::of($r[0], $r[1] ?? [], $r[2] ?? null),
        $rules,
    );

    $ordered = (new RuleOrdering)->order(new RuleSet(['f' => $ruleObjects]));
    $schema = (new DefaultValidationRulesToSchema(ValidationIntegration::transformers()))->convert($ordered, $converter);

    $fromType = $type === null ? [] : $converter->convert($type);

    return [$schema->schema['properties']['f'], $fromType, $registry->schemas()];
}

it('publishes a request field as a $ref to the same component the type chain uses', function (): void {
    [$field, $fromType, $components] = convertRulesBesideType(
        [['enum', ['draft', 'published', 'archived'], WidgetStatus::class]],
        new EnumT(WidgetStatus::class, ['Draft', 'Published', 'Archived']),
    );

    // The point of the whole change: one component, referenced from both directions.
    expect($field['$ref'])->toBe('#/components/schemas/WidgetStatus')
        ->and($fromType['$ref'])->toBe('#/components/schemas/WidgetStatus')
        ->and(array_keys($components))->toBe(['WidgetStatus']);

    // The component carries the enum's own vocabulary, and the field restates none of it.
    expect($components['WidgetStatus']['enum'])->toBe(['draft', 'published', 'archived'])
        ->and($components['WidgetStatus']['x-enum-varnames'])->toBe(['Draft', 'Published', 'Archived'])
        ->and($field)->not->toHaveKey('enum')
        ->and($field)->not->toHaveKey('type');
});

it('keeps its own values where the rule states fewer cases than the enum has', function (): void {
    // A subset is a narrower domain than the component publishes. Referencing it would tell a consumer
    // the server accepts `published`, which this endpoint rejects.
    [$field, , $components] = convertRulesBesideType([['enum', ['draft', 'archived'], WidgetStatus::class]]);

    expect($field)->not->toHaveKey('$ref')
        ->and($field['enum'])->toBe(['draft', 'archived'])
        ->and($components)->toBe([]);
});

it('composes a nullable reference rather than constraining it with a type', function (): void {
    [$field] = convertRulesBesideType([
        ['nullable'],
        ['enum', ['draft', 'published', 'archived'], WidgetStatus::class],
    ]);

    // A `$ref` cannot carry `null`, and `type: [string, null]` beside one would narrow the reference
    // instead of widening it — admitting no value at all.
    expect($field['anyOf'])->toBe([
        ['$ref' => '#/components/schemas/WidgetStatus'],
        ['type' => 'null'],
    ])->and($field)->not->toHaveKey('$ref')
        ->and($field)->not->toHaveKey('type');
});

it('restores the inline set when the document turns enum components off', function (): void {
    [$field, , $components] = convertRulesBesideType(
        [['enum', ['draft', 'published', 'archived'], WidgetStatus::class]],
        null,
        new RepresentationPolicy(enumComponents: false),
    );

    expect($field)->not->toHaveKey('$ref')
        ->and($field['enum'])->toBe(['draft', 'published', 'archived'])
        ->and($field['x-enum-varnames'])->toBe(['Draft', 'Published', 'Archived'])
        ->and($components)->toBe([]);
});

it('keeps the facts about this field beside the reference', function (): void {
    [$field] = convertRulesBesideType([
        ['enum', ['draft', 'published', 'archived'], WidgetStatus::class],
        ['description', ['Where the widget is in its lifecycle.']],
    ]);

    // The component says what the value IS; a description says what this field means by it, and rides
    // alongside as a sibling — which is the shape a property of that type already publishes.
    expect($field['$ref'])->toBe('#/components/schemas/WidgetStatus')
        ->and($field['description'])->toBe('Where the widget is in its lifecycle.');
});
