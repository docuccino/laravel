<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\ApiResources\ResourceStaticsDigestContributor;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ForceWrappedResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\UnsetForceWrapResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\VersionedJsonApiResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/*
 * The wrap statics' digest keys what a service provider changed at boot, on the class that declares
 * each static. A subclass sharing its parent's static is the parent's fact, and a default is its file's,
 * so neither may add to the digest — else it would move with which resources happen to be loaded.
 */
afterEach(function (): void {
    JsonApiResource::$jsonApiInformation = [];
    VersionedJsonApiResource::$jsonApiInformation = ['version' => '1.0'];
    JsonResource::wrap('data');
    if (property_exists(JsonResource::class, 'forceWrapping')) {
        JsonResource::$forceWrapping = false;
        ForceWrappedResource::$forceWrapping = true;
    }
});

it('digests nothing while every static holds what its class declares', function (): void {
    // Loaded, so a scan over the declared classes has a resource of each kind to consider — one
    // declaring its own jsonapi object among them, whose default is its file's fact.
    expect(class_exists(ReleaseResource::class) && class_exists(ForceWrappedResource::class) && class_exists(VersionedJsonApiResource::class))->toBeTrue()
        ->and((new ResourceStaticsDigestContributor)->digest())->toBe('');
});

it('digests a wrap set at boot once, on the class that declares it', function (Closure $set, string $expected): void {
    $set();

    // ReleaseResource shares JsonResource's $wrap, so it reads the same value and adds nothing.
    expect(class_exists(ReleaseResource::class))->toBeTrue()
        ->and((new ResourceStaticsDigestContributor)->digest())->toBe($expected);
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
    expect((new ResourceStaticsDigestContributor)->digest())->toBe(JsonResource::class."\0forceWrapping\0bool\0001");

    JsonResource::$forceWrapping = false;
    ForceWrappedResource::$forceWrapping = false;
    expect((new ResourceStaticsDigestContributor)->digest())->toBe(ForceWrappedResource::class."\0forceWrapping\0bool\0");
});

it('leaves out an anonymous resource, whose name no digest segment can carry', function (): void {
    // Its generated name holds a NUL and the declaring file's path; both would break the segment.
    $anonymous = new class(null) extends JsonResource
    {
        public static $wrap = 'entry';
    };
    $anonymous::wrap('row');

    expect((new ReflectionClass($anonymous))->isAnonymous())->toBeTrue()
        ->and((new ResourceStaticsDigestContributor)->digest())->toBe('');
});

it('reads past a typed wrap static that was declared without a value and never assigned', function (): void {
    // Reading it throws, and one resource the digest cannot read must not fail every build.
    expect(class_exists(UnsetForceWrapResource::class))->toBeTrue()
        ->and((new ReflectionProperty(UnsetForceWrapResource::class, 'forceWrapping'))->isInitialized())->toBeFalse()
        ->and((new ResourceStaticsDigestContributor)->digest())->toBe('');
});

it('digests the jsonapi object configure() sets at boot, by its content', function (): void {
    JsonApiResource::configure(version: '1.1');
    $v11 = (new ResourceStaticsDigestContributor)->digest();
    JsonApiResource::configure(version: '1.1', meta: ['copyright' => 'Example']);
    $withMeta = (new ResourceStaticsDigestContributor)->digest();

    // Every document's `jsonapi` member follows the value, so two values may never share a digest.
    expect($v11)->toStartWith(JsonApiResource::class."\0jsonApiInformation\0array\0")
        ->and($withMeta)->not->toBe($v11)
        ->and($withMeta)->toStartWith(JsonApiResource::class."\0jsonApiInformation\0array\0");
});

it('never digests two jsonapi objects a build publishes differently alike', function (mixed $one, mixed $other): void {
    $digest = static function (mixed $value): string {
        JsonApiResource::$jsonApiInformation = ['version' => $value];

        return (new ResourceStaticsDigestContributor)->digest();
    };

    // A string is published as `{type: string}` and null as `{}` (JsonApiTopLevelTest), and a warm build
    // must equal a cold one — so a value an encoder cannot spell may not collapse into one it can.
    expect($digest($one))->not->toBe($digest($other))
        ->and($digest($one))->toBe($digest($one));
})->with([
    'invalid UTF-8 and null' => ["\xB1", null],
    'two invalid UTF-8 strings' => ["\xB1", "\xB2"],
    'an integer and a float' => [1, 1.0],
    'an integer and its string' => [1, '1'],
    'an object and null' => [new stdClass, null],
]);

it('digests a jsonapi object a resource declares once it holds another value than it declares', function (): void {
    VersionedJsonApiResource::$jsonApiInformation = ['version' => '1.1'];

    expect((new ResourceStaticsDigestContributor)->digest())
        ->toStartWith(VersionedJsonApiResource::class."\0jsonApiInformation\0array\0")
        ->not->toContain(JsonApiResource::class."\0");
});
