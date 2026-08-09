<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\Eloquent\ModelSchema;
use Docuccino\Laravel\Integrations\Eloquent\MorphToSchema;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Gadget;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Polymorphic morph unions → `oneOf` (design §Phase 4). A `discriminator` is emitted only when every
 * variant is morph-mapped (arch I3); an unmapped variant degrades the union to a bare `oneOf` (no
 * discriminator) + an info diagnostic; a nullable morph keeps a null branch; a non-model union falls
 * through to the core mapper.
 */
afterEach(function (): void {
    Relation::morphMap([], false);
});

function morphConverter(ComponentRegistry $components): SchemaConverter
{
    $engine = new StubTypeEngine(classes: [
        Widget::class => new ClassMetadata(Widget::class, [new PropertyMetadata('id', ScalarT::int()), new PropertyMetadata('name', ScalarT::string())]),
        Gadget::class => new ClassMetadata(Gadget::class, [new PropertyMetadata('id', ScalarT::int())]),
    ]);

    return new SchemaConverter([new MorphToSchema, new ModelSchema, ...DefaultTypeMappers::all()], $engine, $components);
}

function morphUnion(): UnionT
{
    return new UnionT([new ClassT(Widget::class), new ClassT(Gadget::class)]);
}

it('maps a model union to a discriminated oneOf keyed by every morph-map alias', function (string $alias, string $fqcn): void {
    Relation::morphMap(['widget' => Widget::class, 'gadget' => Gadget::class], false);

    $schema = morphConverter(new ComponentRegistry)->toSchema(morphUnion())->schema;

    expect($schema)->toHaveKeys(['oneOf', 'discriminator'])
        ->and($schema['oneOf'])->toHaveCount(2)
        ->and($schema['discriminator']['propertyName'])->toBe('type');

    // Every morph-map alias resolves to the corresponding model's component ref.
    $expectedRef = '#/components/schemas/'.class_basename($fqcn);
    expect($schema['discriminator']['mapping'][$alias] ?? null)->toBe($expectedRef);
})->with([
    'widget alias' => ['widget', Widget::class],
    'gadget alias' => ['gadget', Gadget::class],
]);

it('drops the discriminator (bare oneOf) + raises an info diagnostic when a variant is unmapped', function (): void {
    Relation::morphMap(['widget' => Widget::class], false); // gadget deliberately unmapped

    $components = new ComponentRegistry;
    $schema = morphConverter($components)->toSchema(morphUnion())->schema;

    // Polymorphism is not fully evidenced, so no discriminator is emitted — just the oneOf variants.
    expect($schema)->toHaveKey('oneOf')
        ->and($schema)->not->toHaveKey('discriminator')
        ->and($schema['oneOf'])->toHaveCount(2);

    $codes = array_map(static fn ($d): string => $d->code, $components->diagnostics());
    expect($codes)->toContain('eloquent.unmapped-morph');
});

it('keeps a null branch for a nullable morph', function (): void {
    Relation::morphMap(['widget' => Widget::class, 'gadget' => Gadget::class], false);

    $schema = morphConverter(new ComponentRegistry)
        ->toSchema(new UnionT([new ClassT(Widget::class), new ClassT(Gadget::class), new NullT]))
        ->schema;

    expect($schema['oneOf'])->toContain(['type' => 'null'])
        ->and($schema['discriminator']['mapping'])->toHaveKeys(['widget', 'gadget']);
});

it('leaves a non-model union to the core mapper (anyOf, no discriminator)', function (): void {
    $schema = morphConverter(new ComponentRegistry)
        ->toSchema(new UnionT([ScalarT::string(), ScalarT::int()]))
        ->schema;

    expect($schema)->toHaveKey('anyOf')->and($schema)->not->toHaveKey('discriminator');
});

it('declines a single-model union (needs two morph variants), leaving it to the core mapper', function (): void {
    Relation::morphMap(['widget' => Widget::class], false);

    // Only one model member: not a polymorphic morph, so MorphToSchema declines and the core union
    // mapper handles it — no discriminator is emitted for a degenerate one-variant union.
    $schema = morphConverter(new ComponentRegistry)
        ->toSchema(new UnionT([new ClassT(Widget::class), ScalarT::string()]))
        ->schema;

    expect($schema)->not->toHaveKey('discriminator')
        ->and($schema)->toHaveKey('anyOf');
});
