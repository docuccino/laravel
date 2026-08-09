<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\Remove;
use Docuccino\Laravel\Integrations\ApiResources\PaginatedResourceResponsesExtension;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldResourceReflector;

/**
 * Wraps a resource-collection success response in the Laravel paginator envelope once a call-graph
 * trace has recovered that the collection was paginated (and with what kind). Shared by the API
 * Resources ({@see PaginatedResourceResponsesExtension})
 * and json-api-paginate response extensions so the envelope + emit logic is written once.
 *
 * The item schema is taken from a fresh conversion of the collection type (the item component is
 * hoist-deduped, so re-converting adds no components), then rewrapped in the {@see PaginationEnvelope}
 * for the recovered kind and written at INTEGRATION precedence — overriding the inference-layer
 * `{data: [...]}` body the response extension already emitted. Paginated responses always carry the
 * `data` wrapper even under `withoutWrapping`, so any leftover bare-array `items` keyword is removed.
 */
final class PaginatedResponseBody
{
    /**
     * The first plain (non-JSON:API) `AnonymousResourceCollection<T>` return type of the action, or
     * null when the action does not return one (JSON:API collections have their own envelope and are
     * out of scope here).
     */
    public static function resourceCollectionReturn(RouteContext $context): ?ClassT
    {
        foreach ($context->analysis()->returns as $return) {
            $type = $return->type;
            if (! ($type instanceof ClassT && ResourceReflector::isAnonymousCollection($type->fqcn))) {
                continue;
            }

            $item = $type->typeArgs[0] ?? null;
            if ($item instanceof ClassT
                && (ResourceReflector::isJsonApiResource($item->fqcn) || TimacdonaldResourceReflector::isResource($item->fqcn))
            ) {
                continue;
            }

            return $type;
        }

        return null;
    }

    /**
     * Rewrap the 200 response's resource-collection body in the paginator envelope for `$kind`
     * (length/simple/cursor). No-op when the collection body cannot be located.
     */
    public static function wrap(OperationDraft $operation, RouteContext $context, ClassT $collection, string $kind, Contribution $by): void
    {
        $result = $context->converter()->toSchema($collection);
        $items = self::itemsSchema($result->schema);
        if ($items === null) {
            return;
        }

        $envelope = match ($kind) {
            'simple' => PaginationEnvelope::simple($items),
            'cursor' => PaginationEnvelope::cursor($items),
            default => PaginationEnvelope::length($items),
        };

        $response = $operation->response('200');
        $mediaType = $response->primaryMediaType() ?: 'application/json';
        $content = $response->content($mediaType);

        foreach ($envelope as $keyword => $value) {
            $content->set($keyword, $value, $by);
        }

        // Clear a bare-array `items` keyword left by a withoutWrapping inference body (the envelope is
        // an object, so a stray top-level `items` would be invalid).
        $content->set('items', Remove::value(), $by);
    }

    /**
     * The item schema inside a converted resource-collection body: `properties.data.items` (wrapped)
     * or the top-level `items` (a withoutWrapping bare array). An opaque schema fragment (the exact
     * keys are the converter's), null for any other shape.
     *
     * @param  array<string, mixed>  $body
     * @return array<array-key, mixed>|null
     */
    private static function itemsSchema(array $body): ?array
    {
        $properties = $body['properties'] ?? null;
        if (is_array($properties) && is_array($properties['data'] ?? null) && is_array($properties['data']['items'] ?? null)) {
            return $properties['data']['items'];
        }

        if (($body['type'] ?? null) === 'array' && is_array($body['items'] ?? null)) {
            return $body['items'];
        }

        return null;
    }
}
