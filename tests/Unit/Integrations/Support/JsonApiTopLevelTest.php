<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\Support\JsonApiTopLevel;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldResourceReflector;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\MeteredJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\ReleaseResource;
use Docuccino\Laravel\Tests\Fixtures\ApiResources\VersionedJsonApiResource;
use Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi\TimacdonaldArticleResource;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use TiMacDonald\JsonApi\ServerImplementation;

/*
 * Which `with()` each class answers for, and the JSON type each configured value is published as. The
 * contract half — every one checked against the body Laravel sends — is ResourceEnvelopeContractTest.
 */
beforeEach(function (): void {
    // The members for a class, read inside a real conversion so the SchemaContext is the product's.
    $this->members = static function (string $fqcn): ?array {
        $mapper = new class($fqcn) implements TypeToSchema
        {
            /** @var array{properties: array<string, mixed>, required: list<string>}|null */
            public ?array $members = null;

            public function __construct(private readonly string $fqcn) {}

            public function supports(DType $type): bool
            {
                return true;
            }

            public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
            {
                $this->members = JsonApiTopLevel::members($this->fqcn, $context);

                return new SchemaResult([]);
            }
        };

        (new SchemaConverter([$mapper, ...DefaultTypeMappers::all()], new StubTypeEngine, new ComponentRegistry, new RepresentationPolicy))
            ->toSchema(new ClassT('stdClass'));

        return $mapper->members;
    };
});

afterEach(function (): void {
    JsonApiResource::$jsonApiInformation = [];
    app()->offsetUnset(TimacdonaldResourceReflector::SERVER_IMPLEMENTATION_RESOLVER);
});

it('answers for a class whose with() is its family\'s own, and for no other', function (string $fqcn, bool $answers): void {
    $members = ($this->members)($fqcn);

    expect($members !== null)->toBe($answers);
    if ($members !== null) {
        expect(array_keys($members['properties']))->toBe(['included'])
            ->and($members['required'])->toBe([]);
    }
})->with([
    'a first-party resource' => [MeteredJsonApiResource::class, true],
    'the first-party base' => [ResourceReflector::JSON_API_RESOURCE, true],
    'the first-party collection' => [ResourceReflector::JSON_API_COLLECTION, true],
    'a timacdonald resource' => [TimacdonaldArticleResource::class, true],
    'the timacdonald collection' => [TimacdonaldResourceReflector::JSON_API_COLLECTION, true],
    'a plain resource' => [ReleaseResource::class, false],
    'a class with no with()' => ['stdClass', false],
    'a class that does not exist' => ['Docuccino\\Missing\\Resource', false],
]);

it('publishes each configured jsonapi member as the JSON type it is sent as', function (mixed $value, array $schema): void {
    JsonApiResource::$jsonApiInformation = ['member' => $value];

    $members = ($this->members)(ResourceReflector::JSON_API_COLLECTION);

    expect($members['required'] ?? null)->toBe(['jsonapi'])
        ->and($members['properties']['jsonapi']['properties'] ?? null)->toBe(['member' => $schema])
        ->and($members['properties']['jsonapi']['required'] ?? null)->toBe(['member']);
})->with([
    'a string' => ['1.1', ['type' => 'string']],
    'an integer' => [1, ['type' => 'integer']],
    'a float' => [1.1, ['type' => 'number']],
    'a boolean' => [true, ['type' => 'boolean']],
    'a list of URIs' => [['https://example.com/ext'], ['type' => 'array', 'items' => ['type' => 'string']]],
    'a list of anything else' => [[1, 'a'], ['type' => 'array']],
    'a map' => [['copyright' => 'Example'], ['type' => 'object']],
    // Laravel sends it as it is; nothing narrower than any value is true of it.
    'null' => [null, []],
]);

it('reads a resource\'s own jsonapi object for the resource, and the base\'s for the collection', function (): void {
    expect(($this->members)(VersionedJsonApiResource::class)['required'] ?? null)->toBe(['jsonapi'])
        ->and(($this->members)(ResourceReflector::JSON_API_COLLECTION)['required'] ?? null)->toBe([]);
});

it('publishes a bound timacdonald server implementation as optional', function (): void {
    app()->instance(TimacdonaldResourceReflector::SERVER_IMPLEMENTATION_RESOLVER, static fn (): ServerImplementation => new ServerImplementation('1.0'));

    foreach ([TimacdonaldArticleResource::class, TimacdonaldResourceReflector::JSON_API_COLLECTION] as $fqcn) {
        $members = ($this->members)($fqcn);

        expect(array_keys($members['properties'] ?? []))->toBe(['included', 'jsonapi'])
            ->and($members['required'] ?? null)->toBe([]);
    }
});
