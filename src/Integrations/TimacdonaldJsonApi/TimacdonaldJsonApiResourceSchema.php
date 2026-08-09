<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\TimacdonaldJsonApi;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Integrations\Support\JsonApiDocument;

/**
 * Maps a `timacdonald/json-api` resource (`TiMacDonald\JsonApi\JsonApiResource`, guarded by
 * `class_exists`) to a JSON:API document schema through the shared {@see JsonApiDocument} builder —
 * the same `toId`/`toType`/`toAttributes`/`toRelationships`/`toLinks`/`toMeta` surface Laravel 13's
 * first-party resources expose (the package is what they were upstreamed from), so pre-13 apps get
 * identical output. Runs ahead of the always-on `JsonResourceSchema` (a timacdonald resource IS a
 * `JsonResource`), so it wins the chain for these types.
 */
#[ExtensionOrder(priority: Priorities::FIRST)]
final class TimacdonaldJsonApiResourceSchema implements TypeToSchema
{
    public function __construct(
        private readonly JsonApiDocument $document = new JsonApiDocument,
    ) {}

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT && TimacdonaldResourceReflector::isResource($type->fqcn);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        return $this->document->build($type, $context);
    }
}
