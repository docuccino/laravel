<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Laravel\Integrations\Support\JsonApiDocument;
use Docuccino\Laravel\Integrations\Support\ResourceWrapping;

/**
 * Maps a Laravel first-party JSON:API resource
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

        $document = $this->document->build($type, $context);
        if (! $context->atRoot() || ! ResourceWrapping::forced($type->fqcn)) {
            return $document;
        }

        // resolve() returns the document under its own `data` key, which a forced wrap wraps again — while
        // the with() members still merge in at the top, beside the outer key.
        $context->dependsOn(...DeclarationFiles::of($type->fqcn));
        $properties = is_array($document->schema['properties'] ?? null) ? $document->schema['properties'] : [];
        $properties['data'] = [
            'type' => 'object',
            'properties' => ['data' => $properties['data'] ?? []],
            'required' => ['data'],
        ];

        return new SchemaResult([...$document->schema, 'properties' => $properties], $document->confidence);
    }
}
