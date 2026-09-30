<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Laravel\Integrations\ApiResources\CollectionKeys;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\Support\ResourceWrapping;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ChannelResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ChannelShelfCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ForceWrappedResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\KeyedReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\KeyedReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\LinkedReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\PinnedKeysReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\PinnedKeysReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResourceCollection;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\StrayCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The resource a named collection collects, resolved as Laravel's `CollectsResources::collects()` does,
 * whether the collection keeps Laravel's own list body and the keys of what it holds, and whether a
 * resource forces its wrap.
 */
it('resolves what a collection collects', function (string $fqcn, ?string $collects): void {
    expect(ResourceReflector::collects($fqcn))->toBe($collects);
})->with([
    'the $collects property' => [LinkedReleaseCollection::class, ReleaseResource::class],
    // Read ahead of the name only where the installed framework ships the attribute.
    '#[Collects] over the name' => [ReleaseResourceCollection::class, class_exists(ResourceReflector::COLLECTS_ATTRIBUTE) ? ChannelResource::class : ReleaseResource::class],
    'a parent\'s #[Collects], which neither PHP nor Laravel inherits' => [ChannelShelfCollection::class, null],
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

it('knows which collections keep the keys of what they hold', function (ClassT $collection, bool $preserved): void {
    expect(CollectionKeys::preserved($collection))->toBe($preserved);
})->with(function (): array {
    $anonymous = static fn (?string $item): ClassT => new ClassT(ResourceReflector::ANONYMOUS_COLLECTION, $item === null ? [] : [new ClassT($item)]);
    $attribute = class_exists(CollectionKeys::PRESERVE_KEYS_ATTRIBUTE);

    return [
        'collection() of a resource with $preserveKeys' => [$anonymous(KeyedReleaseResource::class), true],
        'collection() of a resource with #[PreserveKeys], where the framework ships it' => [$anonymous(PinnedKeysReleaseResource::class), $attribute],
        'collection() of a resource with neither' => [$anonymous(ReleaseResource::class), false],
        'an anonymous collection of no known resource' => [$anonymous(null), false],
        'a named collection with $preserveKeys' => [new ClassT(KeyedReleaseCollection::class), true],
        'a named collection with #[PreserveKeys], where the framework ships it' => [new ClassT(PinnedKeysReleaseCollection::class), $attribute],
        'a named collection with neither' => [new ClassT(ReleaseCollection::class), false],
        // Only collection() copies the resource's choice; a named collection's is its own.
        'a named collection of a resource with $preserveKeys' => [new ClassT(ReleaseCollection::class, [new ClassT(KeyedReleaseResource::class)]), false],
        'a class that does not exist' => [new ClassT('App\\Http\\Resources\\MissingCollection'), false],
    ];
});

it('publishes kept keys as the array or object they make, and renumbered ones as the list', function (): void {
    $item = ['$ref' => '#/components/schemas/ReleaseResource'];

    expect(CollectionKeys::sent($item, true))->toBe(['type' => ['array', 'object'], 'items' => $item, 'additionalProperties' => $item])
        ->and(CollectionKeys::sent($item, false))->toBe(['type' => 'array', 'items' => $item]);
});

it('knows which resources force their wrap', function (?string $fqcn, bool $forced): void {
    // A framework without $forceWrapping reads no class's, whatever a subclass declares.
    expect(ResourceWrapping::forced($fqcn))->toBe($forced && property_exists(ResourceReflector::JSON_RESOURCE, 'forceWrapping'));
})->with([
    'a resource declaring $forceWrapping' => [ForceWrappedResource::class, true],
    'a resource that does not' => [ReleaseResource::class, false],
    'no resource' => [null, false],
    'a class that does not exist' => ['App\\Http\\Resources\\MissingResource', false],
]);

it('reads a $forceWrapping set for every resource at boot', function (): void {
    if (! property_exists(ResourceReflector::JSON_RESOURCE, 'forceWrapping')) {
        $this->markTestSkipped('The installed framework has no $forceWrapping.');
    }

    JsonResource::$forceWrapping = true;

    try {
        expect(ResourceWrapping::forced(ReleaseResource::class))->toBeTrue();
    } finally {
        JsonResource::$forceWrapping = false;
    }

    expect(ResourceWrapping::forced(ReleaseResource::class))->toBeFalse();
});
