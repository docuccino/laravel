<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Integrations\Support\ResourceWrapping;

/**
 * Maps a Laravel API Resource to a schema (superseding the core class mapper for resource types).
 *
 * - A single `JsonResource` hoists to a component built from its analysed `toArray` shape
 *   ({@see ToArrayObject}) — `whenLoaded`/`when`/`whenNotNull` fields become optional and
 *   `merge`/`mergeWhen` values splice their keys into the parent (optional under `mergeWhen`) —
 *   named by `#[SchemaName]` (else short class name) and pinned by `#[SchemaId]` (else the FQCN).
 * - An anonymous resource collection (`Resource::collection(...)`) renders as an array of its item
 *   schema.
 *
 * A **top-level** resource (the response root, {@see SchemaContext::depth()} === 1) is wrapped under
 * its `data` key per Laravel's `JsonResource::$wrap` semantics ({@see ResourceWrapping}); a **nested**
 * resource (a property of another resource) stays unwrapped so it can be `$ref`-shared.
 *
 * JSON:API resources — first-party ({@see JsonApiResourceSchema}) and the timacdonald family (its own
 * FIRST-priority mapper) — run ahead of this mapper; this mapper explicitly declines BOTH families so
 * a plain flat `toArray` shape is never emitted for a JSON:API resource even if extension ordering is
 * perturbed.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class JsonResourceSchema implements TypeToSchema
{
    /** The pre-13 timacdonald JSON:API base — a JsonResource subclass, so exclude it symmetrically. */
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
            // A timacdonald resource subclasses Illuminate's JsonResource but is a distinct JSON:API
            // family with its own mapper; is_a returns false when the package (and thus the base) is
            // absent, so this is a no-op there.
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

            // Laravel wraps a collection under the COLLECTION's $wrap (AnonymousResourceCollection →
            // 'data'), not the item resource's redeclared $wrap — so resolve the key from the
            // collection type, not its item.
            return $this->wrapTopLevel(new SchemaResult($array, 0.9), $type->fqcn, $context);
        }

        $result = $this->hoist->hoist($context, $type->fqcn, fn (): ?array => $this->toArray->analyze($type->fqcn, 'toArray', $context));

        return $this->wrapTopLevel($result, $type->fqcn, $context);
    }

    /**
     * Wrap a top-level resource/collection schema under its Laravel `data` key; a nested resource
     * ({@see SchemaContext::depth()} > 1) or a `withoutWrapping()`-disabled document is returned as-is.
     */
    private function wrapTopLevel(SchemaResult $result, ?string $itemFqcn, SchemaContext $context): SchemaResult
    {
        if ($context->depth() !== 1) {
            return $result;
        }

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
