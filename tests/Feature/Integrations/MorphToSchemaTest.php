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
use Docuccino\Laravel\Integrations\Eloquent\MorphMapDigestContributor;
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

it('keys the morph digest on the alias a model resolves to, not just on the pairs registered', function (): void {
    // A model can carry two aliases — an application keeping a legacy one alongside the current one —
    // and `array_search()` answers with whichever was registered FIRST, so that is the alias the
    // discriminator publishes. The alias → model pairs are the same set either way, so a digest keying
    // the fragment cache on the pairs alone lets a warm build publish the alias the other order meant.
    $mapping = static function (array $morphMap): array {
        Relation::morphMap($morphMap, false);

        return morphConverter(new ComponentRegistry)->toSchema(morphUnion())->schema['discriminator']['mapping'];
    };
    $digest = static function (array $morphMap): string {
        Relation::morphMap($morphMap, false);

        return (new MorphMapDigestContributor)->digest();
    };

    $widgetFirst = ['widget' => Widget::class, 'legacy_widget' => Widget::class, 'gadget' => Gadget::class];
    $legacyFirst = ['legacy_widget' => Widget::class, 'widget' => Widget::class, 'gadget' => Gadget::class];

    // The premise: the published discriminator really does move with the order, so the digest assertion
    // below is not passing over a document that never changed.
    expect($mapping($widgetFirst))->toHaveKey('widget')
        ->and($mapping($widgetFirst))->not->toHaveKey('legacy_widget')
        ->and($mapping($legacyFirst))->toHaveKey('legacy_widget')
        ->and($mapping($legacyFirst))->not->toHaveKey('widget')
        ->and($digest($legacyFirst))->not->toBe($digest($widgetFirst));
});

it('keys the morph digest on the pairs as a set where no model carries two aliases', function (): void {
    // The other half, and the reason the pair list is still hashed sorted: where every model has one
    // alias, the alias a discriminator publishes is a function of the map's CONTENT, so a reorder moves
    // no byte. Keying that as a sequence would rebuild every fragment over a change nothing can see.
    $digest = static function (array $morphMap): string {
        Relation::morphMap($morphMap, false);

        return (new MorphMapDigestContributor)->digest();
    };

    expect($digest(['gadget' => Gadget::class, 'widget' => Widget::class]))
        ->toBe($digest(['widget' => Widget::class, 'gadget' => Gadget::class]));
});

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
