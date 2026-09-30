<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\ApiResources\ResourceWrapDigestContributor;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ForceWrappedResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\UnsetForceWrapResource;
use Illuminate\Http\Resources\Json\JsonResource;

/*
 * The wrap statics' digest keys what a service provider changed at boot, on the class that declares
 * each static. A subclass sharing its parent's static is the parent's fact, and a default is its file's,
 * so neither may add to the digest — else it would move with which resources happen to be loaded.
 */
afterEach(function (): void {
    JsonResource::wrap('data');
    if (property_exists(JsonResource::class, 'forceWrapping')) {
        JsonResource::$forceWrapping = false;
        ForceWrappedResource::$forceWrapping = true;
    }
});

it('digests nothing while every wrap static holds what its class declares', function (): void {
    // Loaded, so a scan over the declared classes has a resource of each kind to consider.
    expect(class_exists(ReleaseResource::class) && class_exists(ForceWrappedResource::class))->toBeTrue()
        ->and((new ResourceWrapDigestContributor)->digest())->toBe('');
});

it('digests a wrap set at boot once, on the class that declares it', function (Closure $set, string $expected): void {
    $set();

    // ReleaseResource shares JsonResource's $wrap, so it reads the same value and adds nothing.
    expect(class_exists(ReleaseResource::class))->toBeTrue()
        ->and((new ResourceWrapDigestContributor)->digest())->toBe($expected);
})->with([
    'cleared' => [static fn () => JsonResource::withoutWrapping(), JsonResource::class."\0wrap\0null\0"],
    'renamed' => [static fn () => JsonResource::wrap('item'), JsonResource::class."\0wrap\0string\0item"],
    'set back to its default' => [static fn () => JsonResource::wrap('data'), ''],
]);

it('digests a forced wrap set at boot, on the base class and on a resource declaring its own', function (): void {
    if (! property_exists(JsonResource::class, 'forceWrapping')) {
        $this->markTestSkipped('The installed framework has no $forceWrapping.');
    }

    JsonResource::$forceWrapping = true;
    expect((new ResourceWrapDigestContributor)->digest())->toBe(JsonResource::class."\0forceWrapping\0bool\0001");

    JsonResource::$forceWrapping = false;
    ForceWrappedResource::$forceWrapping = false;
    expect((new ResourceWrapDigestContributor)->digest())->toBe(ForceWrappedResource::class."\0forceWrapping\0bool\0");
});

it('leaves out an anonymous resource, whose name no digest segment can carry', function (): void {
    // Its generated name holds a NUL and the declaring file's path; both would break the segment.
    $anonymous = new class(null) extends JsonResource
    {
        public static $wrap = 'entry';
    };
    $anonymous::wrap('row');

    expect((new ReflectionClass($anonymous))->isAnonymous())->toBeTrue()
        ->and((new ResourceWrapDigestContributor)->digest())->toBe('');
});

it('reads past a typed wrap static that was declared without a value and never assigned', function (): void {
    // Reading it throws, and one resource the digest cannot read must not fail every build.
    expect(class_exists(UnsetForceWrapResource::class))->toBeTrue()
        ->and((new ReflectionProperty(UnsetForceWrapResource::class, 'forceWrapping'))->isInitialized())->toBeFalse()
        ->and((new ResourceWrapDigestContributor)->digest())->toBe('');
});
