<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Integrations\Support\JsonApiDocument;

/**
 * Maps a Laravel 13 first-party JSON:API resource
 * (`Illuminate\Http\Resources\JsonApi\JsonApiResource`, guarded by `class_exists`) to a JSON:API
 * document schema via the shared {@see JsonApiDocument} builder — `toAttributes`/`toRelationships`/
 * `toLinks`/`toMeta` become the resource-object members; `id`/`type` are always present strings.
 *
 * Runs ahead of {@see JsonResourceSchema} (a JSON:API resource IS a `JsonResource`), so it wins the
 * chain for these types. The `include`/`fields[type]` query params are added by
 * {@see JsonApiParametersExtension}.
 */
#[ExtensionOrder(priority: Priorities::FIRST)]
final class JsonApiResourceSchema implements TypeToSchema
{
    public function __construct(
        private readonly JsonApiDocument $document = new JsonApiDocument,
    ) {}

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT && ResourceReflector::isJsonApiResource($type->fqcn);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        return $this->document->build($type, $context);
    }
}
