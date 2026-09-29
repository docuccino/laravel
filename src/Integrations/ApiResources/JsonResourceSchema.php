<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Schema\DocumentedExamples;
use Docuccino\Core\Extensions\Schema\MockHints;
use Docuccino\Core\Extensions\Schema\PropertyAnnotations;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Integrations\Support\PaginationEnvelope;
use Docuccino\Laravel\Integrations\Support\ResourceWrapping;
use ReflectionClass;

/**
 * Maps a Laravel API Resource to a schema, superseding the core class mapper for resource types. A
 * `JsonResource` hoists to a component built from its analysed `toArray` shape ({@see ToArrayObject}),
 * named by `#[SchemaName]` and pinned by `#[SchemaId]`; an anonymous collection renders as an array of
 * its item schema, and so does a named one that keeps Laravel's `toArray`.
 *
 * Only a response-root resource is wrapped under its `data` key ({@see ResourceWrapping}), with the
 * top-level members its `with()` adds beside it — Laravel calls `with()` for the root resource alone, so
 * a nested one stays unwrapped and memberless and can be `$ref`-shared.
 *
 * Both JSON:API families have their own higher-priority mappers, and this one explicitly declines them
 * as well, so a flat `toArray` shape can't be emitted for a JSON:API resource even if ordering shifts.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class JsonResourceSchema implements TypeToSchema
{
    /** The pre-13 timacdonald JSON:API base — a JsonResource subclass, hence the explicit exclusion. */
    private const TIMACDONALD_JSON_API_RESOURCE = 'TiMacDonald\\JsonApi\\JsonApiResource';

    public function __construct(
        private readonly ToArrayObject $toArray = new ToArrayObject,
        private readonly ComponentHoist $hoist = new ComponentHoist,
    ) {}

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT
            && ResourceReflector::isResource($type->fqcn)
            && ! ResourceReflector::isJsonApiResource($type->fqcn)
            // is_a returns false when the package isn't installed, so this costs nothing there.
            && ! is_a($type->fqcn, self::TIMACDONALD_JSON_API_RESOURCE, true);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        if (ResourceReflector::isAnonymousCollection($type->fqcn)) {
            $item = $type->typeArgs[0] ?? null;
            $array = ['type' => 'array', 'items' => $item !== null ? $context->convert($item) : []];

            // Laravel wraps under the COLLECTION's $wrap (AnonymousResourceCollection → 'data'), not the
            // item resource's redeclared one — so the key resolves off the collection type.
            return $this->wrapTopLevel(new SchemaResult($array, 0.9), $type->fqcn, $context);
        }

        // The body toArray builds, kept so the root can tell whether it already carries its wrap key.
        $body = null;
        $result = $this->hoist->hoist($context, $type->fqcn, function () use ($type, $context, &$body): ?array {
            // A named collection that keeps Laravel's toArray serialises the resources it collects.
            if (ResourceReflector::inheritsCollectionBody($type->fqcn)) {
                $item = ResourceReflector::collects($type->fqcn);

                return ['type' => 'array', 'items' => $item !== null ? $context->convert(new ClassT($item)) : []];
            }

            $read = $this->toArray->analyzeBody($type->fqcn, $context);

            // A resource's keys come from toArray, not from properties, so only the class-level
            // #[Mock] form can name one; a real property carrying prose still publishes it where its
            // name is one of those keys.
            if ($read === null) {
                return null;
            }
            $object = $body = $read['object'];

            // A resource's own declared properties are the only place a per-field declaration can be
            // written, so their metadata is read for the docblock example the same reflection pass
            // supplies the attributes from — and the files it was assembled from are recorded, since a
            // fact this fragment now depends on may be written in a parent or a trait.
            $metadata = $context->engine()->classMetadata(new ClassRef($type->fqcn));
            $context->dependsOn(...$metadata->dependencyFiles);

            $object = DocumentedExamples::applyTo($context, $object, $type->fqcn, $metadata->properties);
            $object = PropertyAnnotations::applyTo($context, $object, $type->fqcn);

            $object = MockHints::applyTo($context, $object, $type->fqcn);

            // A body that may carry no key is sent as `[]`, so the object is published beside it.
            return $read['mayBeEmpty'] ? ToArrayObject::orEmpty($object) : $object;
        });

        return $this->wrapTopLevel($result, $type->fqcn, $context, $body);
    }

    /**
     * Laravel's `ResourceResponse::wrap()` for a root resource; nested results pass through. The data is
     * wrapped unless it already carries the wrap key, an unwrapped resource is wrapped under `data`
     * whenever `with()` returns anything, and `with()` members merge in beside it.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function wrapTopLevel(SchemaResult $result, string $fqcn, SchemaContext $context, ?array $body = null): SchemaResult
    {
        if (! $context->atRoot()) {
            return $result;
        }

        // `$wrap` is a static property, so a parent resource declaring one decides the envelope of a
        // subclass that mentions it nowhere.
        $context->dependsOn(...DeclarationFiles::of($fqcn));

        $key = ResourceWrapping::key($fqcn, $context->representation());
        $with = $this->withMembers($fqcn, $context);

        // Decided before the wrap key comes off: a `with()` returning only that key still wraps. A named
        // collection may hold a paginator, whose links and meta join whatever its `with()` returns; an
        // anonymous one's pagination is traced per operation (PaginatedResourceResponsesExtension).
        $mayAdd = $with === null || $with['properties'] !== [] || ResourceReflector::isNamedCollection($fqcn);
        $alwaysAdds = $with !== null && $with['required'] !== [];

        // A `with()` key equal to the wrap key merges INTO the data, so it is never a sibling.
        if ($with !== null) {
            unset($with['properties'][$key ?? 'data']);
            $with['required'] = array_values(array_diff($with['required'], [$key ?? 'data']));
            if (ResourceReflector::isNamedCollection($fqcn)) {
                $with['properties'] = PaginationEnvelope::mayMergeInto($with['properties']);
            }
        }

        if ($key !== null) {
            $wrapped = $this->envelope($key, $result->schema, $with);
            $carries = self::carries($body, $key);
            if ($carries === null) {
                return new SchemaResult($wrapped, $result->confidence);
            }

            $bare = self::merged($result->schema, $with);

            return new SchemaResult($carries ? $bare : ['anyOf' => [$wrapped, $bare]], $result->confidence);
        }

        if (! $mayAdd) {
            return $result;
        }

        $wrapped = $this->envelope('data', $result->schema, $with);

        // Unless `with()` always returns a member, the body is bare when it returns nothing and wrapped
        // when it returns something — and an unreadable `with()` may do either.
        return new SchemaResult($alwaysAdds ? $wrapped : ['anyOf' => [$result->schema, $wrapped]], $result->confidence);
    }

    /**
     * Whether toArray's body carries `$key` itself — true on every site, false on some, null on none.
     *
     * @param  array<string, mixed>|null  $body
     */
    private static function carries(?array $body, string $key): ?bool
    {
        if (! is_array($body['properties'] ?? null) || ! array_key_exists($key, $body['properties'])) {
            return null;
        }

        return in_array($key, is_array($body['required'] ?? null) ? $body['required'] : [], true);
    }

    /**
     * toArray's own body with the `with()` members merged in beside its keys.
     *
     * @param  array<string, mixed>  $data
     * @param  array{properties: array<string, mixed>, required: list<string>}|null  $with
     * @return array<string, mixed>
     */
    private static function merged(array $data, ?array $with): array
    {
        if ($with === null || $with['properties'] === []) {
            return $data;
        }

        $members = ['type' => 'object', 'properties' => $with['properties']];
        if ($with['required'] !== []) {
            $members['required'] = $with['required'];
        }

        return ['allOf' => [$data, $members]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{properties: array<string, mixed>, required: list<string>}|null  $with
     * @return array<string, mixed>
     */
    private function envelope(string $key, array $data, ?array $with): array
    {
        return [
            'type' => 'object',
            'properties' => [$key => $data, ...($with['properties'] ?? [])],
            'required' => [$key, ...($with['required'] ?? [])],
        ];
    }

    /**
     * The members `with()` adds to the response root, analysed as `toArray` is but unfiltered, since
     * Laravel merges it as returned. Laravel's own returns the `$with` property: none when it defaults to
     * empty, and unread when the class gives it a default, whose value is data rather than a shape this
     * reads. A caller assigning the property, like one calling `additional()`, is not traced. Null when
     * no shape can be read.
     *
     * @return array{properties: array<string, mixed>, required: list<string>}|null
     */
    private function withMembers(string $fqcn, SchemaContext $context): ?array
    {
        if (! class_exists($fqcn) || ! method_exists($fqcn, 'with')) {
            return null;
        }

        $class = new ReflectionClass($fqcn);
        $declaring = $class->getMethod('with')->getDeclaringClass()->getName();
        $default = $class->getDefaultProperties()['with'] ?? null;

        if ($declaring === ResourceReflector::JSON_RESOURCE && $default === []) {
            return ['properties' => [], 'required' => []];
        }

        $object = $declaring === ResourceReflector::JSON_RESOURCE ? null : $this->toArray->analyze($fqcn, 'with', $context, false);
        if ($object === null) {
            $context->lowerConfidence(0.8);

            return null;
        }

        /** @var array<string, mixed> $properties */
        $properties = is_array($object['properties'] ?? null) ? $object['properties'] : [];
        /** @var list<string> $required */
        $required = is_array($object['required'] ?? null) ? array_values($object['required']) : [];

        return ['properties' => $properties, 'required' => $required];
    }
}
