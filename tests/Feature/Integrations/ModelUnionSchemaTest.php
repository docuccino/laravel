<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\DiscriminatedUnion;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\Eloquent\ModelSchema;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Gadget;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;
use Illuminate\Database\Eloquent\Relations\Relation;
use Opis\JsonSchema\Validator as OpisValidator;

/*
 * A union of models — what `MorphTo<Widget|Gadget>` resolves to — is published by the same rule as any
 * other union of components: an `anyOf`, discriminated only where the members' own bodies prove it
 * ({@see DiscriminatedUnion}). The morph map names a model in the PARENT's `*_type` column, never in the
 * model's own serialised body, so it is no evidence about the payload a discriminator reads.
 */
afterEach(function (): void {
    Relation::morphMap([], false);
});

/**
 * Convert a model union with the given property types per model, settled as the assembler settles it.
 *
 * @param  array<string, array<string, DType>>  $models  FQCN → property name → type
 * @return array{schema: array<string, mixed>, components: array<string, array<string, mixed>>}
 */
function modelUnion(array $models): array
{
    $metadata = [];
    foreach ($models as $fqcn => $properties) {
        $metadata[$fqcn] = new ClassMetadata($fqcn, array_map(
            static fn (string $name, DType $type): PropertyMetadata => new PropertyMetadata($name, $type),
            array_keys($properties),
            array_values($properties),
        ));
    }

    $components = new ComponentRegistry;
    $schema = (new SchemaConverter([new ModelSchema, ...DefaultTypeMappers::all()], new StubTypeEngine(classes: $metadata), $components))
        ->toSchema(UnionT::of([new ClassT(Widget::class), new ClassT(Gadget::class)]))
        ->schema;

    [$doc] = DiscriminatedUnion::settle(['paths' => ['/x' => $schema], 'components' => ['schemas' => $components->schemas()]]);

    /** @var array{schema: array<string, mixed>, components: array<string, array<string, mixed>>} */
    return ['schema' => $doc['paths']['/x'], 'components' => $doc['components']['schemas']];
}

it('publishes a morph-mapped model union as the anyOf of its models, with no discriminator', function (): void {
    Relation::morphMap(['widget' => Widget::class, 'gadget' => Gadget::class], false);

    $result = modelUnion([
        Widget::class => ['id' => ScalarT::int(), 'name' => ScalarT::string()],
        Gadget::class => ['id' => ScalarT::int()],
    ]);

    expect($result['schema'])->toBe(['anyOf' => [
        ['$ref' => '#/components/schemas/Gadget'],
        ['$ref' => '#/components/schemas/Widget'],
    ]]);

    // A discriminator names a property of the PAYLOAD. A serialised model carries no property holding its
    // morph alias, so a discriminator on `type` sends every client looking for a field that never arrives.
    expect($result['components']['Widget']['properties'])->not->toHaveKey('type')
        ->and($result['components']['Gadget']['properties'])->not->toHaveKey('type');

    // And `oneOf` asserts exactly one member matches. The bodies are open objects, so a Widget also
    // satisfies Gadget's `{id}` — a `oneOf` rejects the very response the server sends; `anyOf` accepts it.
    $asJson = static function (mixed $node) use (&$asJson): mixed {
        if (! is_array($node)) {
            return $node;
        }
        // A property schema of `{}` is an empty PHP array, which would otherwise encode as a list.
        $mapped = array_map(static fn (mixed $child): mixed => $child === [] ? new stdClass : $asJson($child), $node);

        return array_is_list($mapped) ? $mapped : (object) $mapped;
    };
    $union = static fn (string $keyword): mixed => $asJson([$keyword => [$result['components']['Gadget'], $result['components']['Widget']]]);
    $widget = json_decode('{"id":1,"name":"a widget","created_at":"2026-01-01T00:00:00Z","is_active":true,"status":"Draft","meta":{},"updated_at":"2026-01-01T00:00:00Z"}');

    expect((new OpisValidator)->validate($widget, $asJson($result['components']['Widget']))->isValid())->toBeTrue()
        ->and((new OpisValidator)->validate($widget, $union('oneOf'))->isValid())->toBeFalse()
        ->and((new OpisValidator)->validate($widget, $union('anyOf'))->isValid())->toBeTrue();
});

it('discriminates a model union whose bodies pin a tag, by the same rule as any class', function (): void {
    // No mapper claims a model union ahead of the core one, so bodies that do carry a tag are read.
    $result = modelUnion([
        Widget::class => ['name' => new LiteralT('widget'), 'id' => ScalarT::int()],
        Gadget::class => ['name' => new LiteralT('gadget'), 'id' => ScalarT::int()],
    ]);

    expect($result['schema']['discriminator'])->toBe([
        'propertyName' => 'name',
        'mapping' => [
            'gadget' => '#/components/schemas/Gadget',
            'widget' => '#/components/schemas/Widget',
        ],
    ]);
});
