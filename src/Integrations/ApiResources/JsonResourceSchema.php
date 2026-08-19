<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Schema\MockHints;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Integrations\Support\ResourceWrapping;

/**
 * Maps a Laravel API Resource to a schema, superseding the core class mapper for resource types. A
 * `JsonResource` hoists to a component built from its analysed `toArray` shape ({@see ToArrayObject}),
 * named by `#[SchemaName]` and pinned by `#[SchemaId]`; an anonymous collection renders as an array of
 * its item schema.
 *
 * Only a response-root resource is wrapped under its `data` key ({@see ResourceWrapping}) — a nested one
 * stays unwrapped so it can be `$ref`-shared.
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

        $result = $this->hoist->hoist($context, $type->fqcn, function () use ($type, $context): ?array {
            $object = $this->toArray->analyze($type->fqcn, 'toArray', $context);

            // A resource's keys come from toArray, not from properties, so only the class-level
            // #[Mock] form can name one.
            return $object === null ? null : MockHints::applyTo($context, $object, $type->fqcn);
        });

        return $this->wrapTopLevel($result, $type->fqcn, $context);
    }

    /** Wraps a root resource under its `data` key; nested or `withoutWrapping()` results pass through. */
    private function wrapTopLevel(SchemaResult $result, ?string $itemFqcn, SchemaContext $context): SchemaResult
    {
        if ($context->depth() !== 1) {
            return $result;
        }

        // `$wrap` is a static property, so a parent resource declaring one decides the envelope of a
        // subclass that mentions it nowhere.
        $context->dependsOn(...DeclarationFiles::of($itemFqcn));

        $key = ResourceWrapping::key($itemFqcn, $context->representation());
        if ($key === null) {
            return $result;
        }

        return new SchemaResult([
            'type' => 'object',
            'properties' => [$key => $result->schema],
            'required' => [$key],
        ], $result->confidence);
    }
}
