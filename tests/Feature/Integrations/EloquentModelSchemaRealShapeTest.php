<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Inference\PhpStan\Tests\Support\FixtureRunner;
use Docuccino\Laravel\Integrations\Eloquent\EloquentModelReflector;
use Docuccino\Laravel\Integrations\Eloquent\ModelSchema;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Post;

/**
 * The other half of the model schema's key filter, over the REAL engine. A model's visibility lists are
 * reflected off the class, so the workbench goldens pin them; the framework's own public properties are
 * not — they arrive in the metadata, and only a real `classMetadata()` over a real Eloquent model reports
 * them. So this is the one place the drop is exercised on the input that actually carries it.
 *
 * The metadata is re-keyed onto an in-process twin because the mapper reflects the FQCN it is handed and
 * nothing in this process can load a fixture-app class. The twin declares no visibility list and no cast,
 * so what separates the two lists below is the bookkeeping drop alone.
 */
beforeEach(function (): void {
    ensureFixtureAvailable(FixtureRunner::available());
});

it('publishes none of the framework bookkeeping the real engine reports beside a model\'s columns', function (): void {
    $real = ClassMetadata::fromArray(FixtureRunner::classMetadata('App\\Models\\Product'));
    $reported = array_map(static fn ($property): string => $property->name, $real->properties);

    $twin = new ClassMetadata(Post::class, $real->properties, $real->summary);
    $components = new ComponentRegistry;
    (new SchemaConverter(
        [new ModelSchema, ...DefaultTypeMappers::all()],
        new StubTypeEngine(classes: [Post::class => $twin]),
        $components,
    ))->toSchema(new ClassT(Post::class));

    $published = array_keys($components->schemas()['Post']['properties']);

    // The drop proves nothing unless the engine handed the mapper something to drop.
    expect(array_diff($reported, $published))->not->toBeEmpty()
        // Exactly the bookkeeping goes, and everything else the engine reported stays: a filter that
        // took a real column with it would be as wrong as one that published none.
        ->and($published)->toBe(array_values(array_diff($reported, EloquentModelReflector::frameworkProperties())));
})->group('fixture');
