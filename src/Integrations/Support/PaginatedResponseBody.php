<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Integrations\ApiResources\PaginatedResourceResponsesExtension;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldResourceReflector;
use Docuccino\Laravel\Support\IgnoredResponses;

/**
 * Wraps a resource-collection success response in the Laravel paginator envelope, once a trace has
 * recovered that the collection was paginated and with what kind. Shared by the API Resources
 * ({@see PaginatedResourceResponsesExtension}) and json-api-paginate response extensions.
 *
 * The item schema comes from a fresh conversion of the collection type (hoist-deduped, so re-converting
 * costs no components), then goes into the {@see PaginationEnvelope} for that kind at INTEGRATION
 * precedence, replacing the inference-layer `{data: [...]}` body already emitted. It is written as one
 * declared shape, so the keywords that body left behind come off with it — Laravel keeps the `data`
 * wrapper on paginated responses even under `withoutWrapping`, and a leftover bare-array `items` beside
 * an object envelope would be invalid.
 *
 * The envelope's `links`/`meta` are hoisted to one component per shape ({@see PaginationParts}), and the
 * envelope itself to one per item type and kind where it can be ({@see PageComponent}) — so the body is
 * declared as a bare `$ref` and the inline form's keywords come off the same way.
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

    /**
     * Rewraps the 200 body in the envelope for `$kind`. No-op when the body can't be located, and no-op
     * when the route drops its 200 — the conversion below is what hoists the item schema, the envelope's
     * links/meta parts and the page component, so the check has to come first ({@see IgnoredResponses}).
     */
    public static function wrap(OperationDraft $operation, RouteContext $context, ClassT $collection, string $kind, Contribution $by): void
    {
        if (IgnoredResponses::drops($context, '200')) {
            return;
        }

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

        // Either form is the whole body, so it is declared as one shape: the keywords the inference-layer
        // `{data: […]}` — or a withoutWrapping bare array — left behind come off with the shape they
        // described, which is what leaves a bare `$ref` where the component publishes.
        $content->declareShape($reference ?? $envelope, $by);
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
