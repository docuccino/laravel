<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\JsonResourceSchema;
use Docuccino\Laravel\Integrations\ApiResources\WrappedResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ConditionalWithResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\GazetteCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\GazetteResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * A collection's `with()` read for what the collection wraps, where a caller proved it: the returns the
 * engine finds reachable for that class, else — a narrowing no return survives, or nothing proved — the
 * whole method, which is what was published before anything was known.
 */
beforeEach(function (): void {
    $location = new SourceLocation('');
    $this->sites = static fn (array $types): ActionAnalysis => new ActionAnalysis(returns: array_map(static fn ($type): ReturnSite => new ReturnSite($type, $location), $types));
    $this->meta = new ArrayShapeT([new ArrayShapeField('meta', new ArrayShapeT([new ArrayShapeField('count', ScalarT::int())]))]);
    $this->whole = ($this->sites)([new ListT(new UnknownT('mixed')), $this->meta]);
    $this->narrowed = static fn (string $class, string $resource): string => (new CallableRef('', $class, 'with', narrowType: $resource, narrowToEvery: true, narrowProperty: 'resource'))->symbol();

    $this->convert = function (StubTypeEngine $engine, string $type, ?string $wraps): array {
        $converter = new SchemaConverter([new JsonResourceSchema, ...DefaultTypeMappers::all()], $engine, new ComponentRegistry);
        $class = $type === GazetteCollection::class ? new ClassT($type, [new ClassT(GazetteResource::class)]) : new ClassT($type);

        return $wraps === null
            ? $converter->toSchema($class)->schema
            : WrappedResource::during($converter, $wraps, static fn () => $converter->toSchema($class))->schema;
    };
});

it('holds what a conversion wraps for that converter alone, and only while it runs', function (): void {
    $engine = new StubTypeEngine;
    $one = new SchemaConverter([], $engine, new ComponentRegistry);
    $other = new SchemaConverter([], $engine, new ComponentRegistry);

    $seen = WrappedResource::during($one, WrappedResource::PLAIN, static fn (): array => [
        WrappedResource::of($one),
        WrappedResource::of($other),
        WrappedResource::during($one, WrappedResource::PAGINATORS['cursor'], static fn (): ?string => WrappedResource::of($one)),
        WrappedResource::of($one),
    ]);

    expect($seen)->toBe([WrappedResource::PLAIN, null, WrappedResource::PAGINATORS['cursor'], WrappedResource::PLAIN])
        ->and(WrappedResource::of($one))->toBeNull();

    // A conversion that throws still leaves nothing behind.
    try {
        WrappedResource::during($one, WrappedResource::PLAIN, static fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }
    expect(WrappedResource::of($one))->toBeNull();
});

it('names the paginator every page kind Laravel builds is', function (string $kind, string $class): void {
    expect(WrappedResource::PAGINATORS[$kind])->toBe($class)
        ->and(class_exists($class))->toBeTrue();
})->with([
    'length' => ['length', LengthAwarePaginator::class],
    'simple' => ['simple', Paginator::class],
    'cursor' => ['cursor', CursorPaginator::class],
]);

it('leaves a plain list the class collectResource() leaves it', function (mixed $resource): void {
    // What `$this->resource` is once Laravel has collected a list, which the narrowing names exactly.
    $collection = new GazetteCollection($resource, GazetteResource::class);

    expect($collection->resource::class)->toBe(WrappedResource::PLAIN);
})->with([
    'an array' => [[(object) ['name' => 'a']]],
    'the base collection' => [collect([(object) ['name' => 'a']])],
    'an Eloquent collection' => [new Collection([(object) ['name' => 'a']])],
]);

it('reads a collection\'s with() for the class it wraps where one is proved', function (): void {
    $engine = new StubTypeEngine(analyses: [GazetteCollection::class.'::with' => $this->whole], callables: [
        ($this->narrowed)(GazetteCollection::class, WrappedResource::PLAIN) => ($this->sites)([$this->meta]),
    ]);

    $unknown = ($this->convert)($engine, GazetteCollection::class, null);
    $plain = ($this->convert)($engine, GazetteCollection::class, WrappedResource::PLAIN);

    expect($unknown['required'])->toBe(['data'])
        ->and($plain['required'])->toBe(['data', 'meta'])
        ->and($plain['properties']['meta'])->toBe($unknown['properties']['meta']);
});

it('reads the whole with() where no return survives the narrowing', function (): void {
    $engine = new StubTypeEngine(analyses: [GazetteCollection::class.'::with' => $this->whole]);

    expect(($this->convert)($engine, GazetteCollection::class, WrappedResource::PLAIN))
        ->toBe(($this->convert)($engine, GazetteCollection::class, null));
});

it('never narrows a single resource\'s with(), whose $this->resource is the model it was handed', function (): void {
    // Only a collection's root is a list or a page; a hint left set elsewhere must not reach a resource.
    $engine = new StubTypeEngine(analyses: [ConditionalWithResource::class.'::with' => $this->whole], callables: [
        ($this->narrowed)(ConditionalWithResource::class, WrappedResource::PLAIN) => ($this->sites)([$this->meta]),
    ]);

    expect(($this->convert)($engine, ConditionalWithResource::class, WrappedResource::PLAIN))
        ->toBe(($this->convert)($engine, ConditionalWithResource::class, null));
});
