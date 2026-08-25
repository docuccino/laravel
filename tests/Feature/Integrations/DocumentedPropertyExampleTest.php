<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
use Docuccino\Core\Extensions\Context\RouteDependencies;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Docuccino\Laravel\Integrations\Eloquent\ModelSchema;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\RetentionResource;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Persona;

/**
 * The Eloquent and API-resource mappers reading a property's docblock `@example` — the two of the three
 * producers on the ledger whose idiomatic shape turns out to have nowhere to write the tag.
 *
 * Both now consult the same reader in the same order as the Data mapper and the generic class mapper,
 * so an example that IS recoverable publishes, and the attribute still beats it. What neither can do is
 * conjure a tag the shape cannot hold: a model's columns are magic `@property` tags with no docblock of
 * their own, and an idiomatic resource publishes `toArray` keys no property backs. Those limits are
 * pinned here rather than left implied, and their recovery half is pinned against the real analyser in
 * the engine's DocumentedExampleRecoveryTest.
 */
function documentedResourceRegistry(): ComponentRegistry
{
    $loc = new SourceLocation('');
    $components = new ComponentRegistry;

    $engine = new StubTypeEngine(
        analyses: [
            RetentionResource::class.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                new ArrayShapeField('plan', ScalarT::string()),
                new ArrayShapeField('days', ScalarT::int()),
                new ArrayShapeField('grace_days', ScalarT::int()),
            ]), $loc)]),
        ],
        classes: [
            // Mirrors the tags the fixture really carries; text is all a docblock tag can hold.
            RetentionResource::class => new ClassMetadata(RetentionResource::class, [
                new PropertyMetadata('plan', ScalarT::string(), 'The plan this policy belongs to.', 'from-the-docblock'),
                new PropertyMetadata('days', ScalarT::int(), null, '90'),
                new PropertyMetadata('grace_days', ScalarT::int(), null, 'n/a'),
            ]),
        ],
    );

    (new SchemaConverter([new JsonResourceSchema, ...DefaultTypeMappers::all()], $engine, $components))
        ->toSchema(new ClassT(RetentionResource::class));

    return $components;
}

it('publishes a resource field\'s docblock example where a real property backs the key', function (): void {
    // The reachable half: a resource that declares the properties it publishes. Before this the tag was
    // written and dropped, while the same tag on a Data class property reached the document.
    /** @var array<string, mixed> $properties */
    $properties = documentedResourceRegistry()->schemas()['RetentionResource']['properties'];

    expect($properties['days']['example'])->toBe(90);
});

it('leaves the attribute standing where a resource property carries both', function (): void {
    // Docblock 30 < attribute 40, the same order on a resource as on a plain DTO.
    /** @var array<string, mixed> $properties */
    $properties = documentedResourceRegistry()->schemas()['RetentionResource']['properties'];

    expect($properties['plan']['example'])->toBe('from-the-attribute');
});

it('publishes no resource example it cannot read, and names the property', function (): void {
    $registry = documentedResourceRegistry();
    /** @var array<string, mixed> $properties */
    $properties = $registry->schemas()['RetentionResource']['properties'];

    expect($properties['grace_days'])->not->toHaveKey('example')
        ->and(array_map(static fn ($d): string => $d->code, $registry->diagnostics()))->toBe(['docblock.example-untypable'])
        ->and($registry->diagnostics()[0]->message)->toContain('RetentionResource::$grace_days');
});

it('publishes no example for a model whose columns are magic properties, and says nothing about it', function (): void {
    // The idiomatic Eloquent shape, and the honest outcome: `@property int $id` has no docblock of its
    // own for an `@example` to sit in, so there is nothing to read and nothing the author could do — a
    // diagnostic here would fire where the reader cannot act.
    $components = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [
        Persona::class => new ClassMetadata(Persona::class, [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('email', ScalarT::string()),
        ]),
    ]);

    (new SchemaConverter([new ModelSchema, new EnumSchema, ...DefaultTypeMappers::all()], $engine, $components))
        ->toSchema(new ClassT(Persona::class));

    /** @var array<string, mixed> $properties */
    $properties = $components->schemas()['Persona']['properties'];

    // A scan that matched nothing would pass forever, so the columns are asserted present first.
    expect(array_keys($properties))->toContain('id', 'email');

    foreach ($properties as $schema) {
        expect($schema)->not->toHaveKey('example');
    }

    expect(array_map(static fn ($d): string => $d->code, $components->diagnostics()))->not->toContain('docblock.example-untypable');
});

it('records the files a resource\'s property declarations were assembled from', function (): void {
    // The resource mapper reads class metadata it never read before, so a fact it now depends on may be
    // written in a parent or a trait rather than the resource's own file. Under-keying a fragment on
    // that is a correctness bug: a warm build would keep publishing an example the author had removed.
    $loc = new SourceLocation('');
    $components = new ComponentRegistry;
    $dependencies = new RouteDependencies;

    $engine = new StubTypeEngine(
        analyses: [
            RetentionResource::class.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                new ArrayShapeField('days', ScalarT::int()),
            ]), $loc)]),
        ],
        classes: [
            RetentionResource::class => new ClassMetadata(
                RetentionResource::class,
                [new PropertyMetadata('days', ScalarT::int(), null, '90')],
                dependencyFiles: ['/parent/that/declared/it.php'],
            ),
        ],
    );

    (new SchemaConverter(
        [new JsonResourceSchema, ...DefaultTypeMappers::all()],
        $engine,
        $components,
        dependencies: $dependencies,
    ))->toSchema(new ClassT(RetentionResource::class));

    expect($dependencies->files())->toContain('/parent/that/declared/it.php');
});
