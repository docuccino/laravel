<?php

declare(strict_types=1);

use Docuccino\Laravel\Versioning\OasTypeNames;
use Docuccino\Laravel\Versioning\Scaffold\RemovedFieldShape;

/*
 * How a removed field's `type:` is spelled, over every name the verb reads back and the two suffixes
 * beside them.
 *
 * The pairing with {@see OasTypeNames} is the point: this writes a spelling and that reads it, so a
 * name one knows and the other does not is a `versioning.type-unresolved` diagnostic on a shape the
 * scaffolder had in its hand. The row set is derived from `OasTypeNames::NAMES` rather than typed out,
 * and the guard below fails if the two ever differ in size.
 */

/**
 * @param  array<string, mixed>  $property
 */
function spelledShape(array $property, array $classesByRef = []): ?string
{
    return RemovedFieldShape::spell($property, $classesByRef);
}

it('spells every OpenAPI type name the verb reads', function (string $name): void {
    expect(spelledShape(['type' => $name]))->toBe($name)
        // And what it spelled is a spelling the verb reads back, rather than a word it invents.
        ->and(OasTypeNames::read($name))->not->toBeNull();
})->with(array_combine(OasTypeNames::NAMES, array_map(static fn (string $n): array => [$n], OasTypeNames::NAMES)));

it('covers every name the verb reads with a row of its own', function (): void {
    // The dataset above is derived, so this only has to catch the list shrinking to nothing — a table
    // that stopped enumerating would pass every assertion above and prove none of them.
    expect(OasTypeNames::NAMES)->toHaveCount(6);
});

it('spells the two suffixes, and reads them back the same way round', function (): void {
    expect(spelledShape(['type' => ['string', 'null']]))->toBe('string?')
        ->and(spelledShape(['type' => 'array', 'items' => ['type' => 'number']]))->toBe('number[]')
        ->and(spelledShape(['type' => ['array', 'null'], 'items' => ['type' => 'number']]))->toBe('number[]?')
        ->and(OasTypeNames::read('number[]?'))->toBe(['type' => ['array', 'null'], 'items' => ['type' => 'number']]);
});

it('spells a class the head still publishes as a pointer at it', function (): void {
    expect(spelledShape(['$ref' => '#/components/schemas/Author'], ['#/components/schemas/Author' => 'App\\Data\\Author']))
        ->toBe('App\\Data\\Author::class')
        ->and(RemovedFieldShape::classOf('App\\Data\\Author::class'))->toBe('App\\Data\\Author')
        ->and(RemovedFieldShape::classOf('string'))->toBeNull();
});

it('spells nothing for a shape the verb could not read', function (mixed $property): void {
    // The degraded answer, and the one the author is told about: the verb publishes an unconstrained
    // field, which is honest, where a spelling it cannot parse would publish one AND complain.
    expect(spelledShape(is_array($property) ? $property : []))->toBeNull();
})->with([
    'no type at all' => [[]],
    'a type that is not a name' => [['type' => 'money']],
    'a union of two real types' => [['type' => ['string', 'integer']]],
    'a nullable that names nothing' => [['type' => ['null']]],
    'a type that is not a string' => [['type' => 7]],
    'a pointer at a component no class produces' => [['$ref' => '#/components/schemas/Author']],
]);

it('degrades a list it cannot spell the members of to a bare array', function (): void {
    // Still true — it was an array — and `array` is a name the verb reads, so the field is published
    // as one rather than as no shape at all.
    expect(spelledShape(['type' => 'array']))->toBe('array')
        ->and(spelledShape(['type' => 'array', 'items' => ['type' => 'money']]))->toBe('array')
        ->and(spelledShape(
            ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Author']],
            ['#/components/schemas/Author' => 'App\\Data\\Author'],
        ))->toBe('array');
});
