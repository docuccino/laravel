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
 * Wraps a resource-collection success response in the Laravel paginator envelope, once a trace has
 * recovered that the collection was paginated and with what kind. Shared by the API Resources
 * ({@see PaginatedResourceResponsesExtension}) and json-api-paginate response extensions.
 *
 * The item schema comes from a fresh conversion of the collection type (hoist-deduped, so re-converting
 * costs no components), then goes into the {@see PaginationEnvelope} for that kind at INTEGRATION
 * precedence, overriding the inference-layer `{data: [...]}` body already emitted. Laravel keeps the
 * `data` wrapper on paginated responses even under `withoutWrapping`, so a leftover bare-array `items`
 * keyword is removed.
 *
 * The envelope's `links`/`meta` are hoisted to one component per shape ({@see PaginationParts}), and the
 * envelope itself to one per item type and kind where it can be ({@see PageComponent}) — so the body
 * becomes a `$ref` and every keyword the inline form wrote comes back off.
 */
final class PaginatedResponseBody
{
    /**
     * The action's first plain `AnonymousResourceCollection<T>` return type. JSON:API collections have
     * their own envelope, so they're skipped.
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

    /** Rewraps the 200 body in the envelope for `$kind`. No-op when the body can't be located. */
    public static function wrap(OperationDraft $operation, RouteContext $context, ClassT $collection, string $kind, Contribution $by): void
    {
        $result = $context->converter()->toSchema($collection);
        $items = self::itemsSchema($result->schema);
        if ($items === null) {
            return;
        }

        $envelope = PaginationParts::hoist(
            $context->converter(),
            PaginationEnvelope::of($kind, $items),
            PaginationEnvelope::parts($kind),
        );

        $item = $collection->typeArgs[0] ?? null;
        $reference = PageComponent::reference(
            $context->converter(),
            $kind,
            $item instanceof ClassT ? $item->fqcn : null,
            $items,
            $envelope,
        );

        $response = $operation->response('200');
        $mediaType = $response->primaryMediaType() ?: 'application/json';
        $content = $response->content($mediaType);

        if ($reference !== null) {
            // The whole body is the component now, so every keyword the inline envelope would have
            // written — plus the `items` below — comes off, and a bare `$ref` is left in their place.
            foreach (['type', 'properties', 'required', 'items'] as $keyword) {
                $content->set($keyword, Remove::value(), $by);
            }

            $content->set('$ref', $reference['$ref'], $by);

            return;
        }

        foreach ($envelope as $keyword => $value) {
            $content->set($keyword, $value, $by);
        }

        // The envelope is an object, so a top-level `items` left by a withoutWrapping body is invalid.
        $content->set('items', Remove::value(), $by);
    }

    /**
     * The item schema inside a converted collection body — `properties.data.items` when wrapped, the
     * top-level `items` for a withoutWrapping bare array. Null for any other shape.
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
