<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\LinkedReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\StrayCollection;

/**
 * The resource a named collection collects, resolved as Laravel's `CollectsResources::collects()` does,
 * and whether the collection keeps Laravel's own list body.
 */
it('resolves what a collection collects', function (string $fqcn, ?string $collects): void {
    expect(ResourceReflector::collects($fqcn))->toBe($collects);
})->with([
    'the $collects property' => [LinkedReleaseCollection::class, ReleaseResource::class],
    'FooCollection names FooResource' => [ReleaseCollection::class, ReleaseResource::class],
    'a $collects that is not a resource, which Laravel refuses' => [StrayCollection::class, null],
    'a name pointing at nothing' => [ResourceReflector::RESOURCE_COLLECTION, null],
    'a class that does not exist' => ['App\\Http\\Resources\\MissingCollection', null],
]);

it('knows which collections keep Laravel\'s own list body', function (string $fqcn, bool $inherits): void {
    expect(ResourceReflector::inheritsCollectionBody($fqcn))->toBe($inherits);
})->with([
    'a named collection without its own toArray' => [ReleaseCollection::class, true],
    'a named collection with its own toArray' => [LinkedReleaseCollection::class, false],
    'an anonymous collection' => [ResourceReflector::ANONYMOUS_COLLECTION, false],
    'a single resource' => [ReleaseResource::class, false],
    'a class that does not exist' => ['App\\Http\\Resources\\MissingCollection', false],
]);
