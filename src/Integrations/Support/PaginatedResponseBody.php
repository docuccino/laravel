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
use Docuccino\Laravel\Support\FrameworkClasses;
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
     * The action's first plain `AnonymousResourceCollection<T>` return type, bare or rendered through the
     * framework's `->response()` ({@see FrameworkClasses::selfRendered()}) — the same 200 body either way.
     * JSON:API collections have their own envelope, so they're skipped.
     */
    public static function resourceCollectionReturn(RouteContext $context): ?ClassT
    {
        foreach ($context->analysis()->returns as $return) {
            $type = FrameworkClasses::selfRendered($return->type);
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
        $content->declareShape(self::withMembers($reference ?? $envelope, $envelope, $kind, $result->schema), $by);
    }

    /**
     * The page with the members the collection's `with()` adds beside it — the converted body's own members
     * beside `data`, composed next to the page rather than into it, because the page component is shared by
     * every collection of the item and `with()` belongs to one collection class.
     *
     * Laravel merges them into the pagination information with `array_merge_recursive`, so an object named
     * `links` or `meta` extends that part, which `allOf` states of both at once. Anything else under those
     * names is appended into the part as a positional member, which the open part already admits, so it is
     * left out rather than claimed as the part's type. A key the part already sends is merged with it into
     * an array, so where one collides the part is restated inline with that key widened, and the page is
     * never referenced: the page component's type for the key is no longer what is sent.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $envelope  the page inline, its parts as references
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function withMembers(array $page, array $envelope, string $kind, array $body): array
    {
        $properties = is_array($body['properties'] ?? null) ? $body['properties'] : [];
        unset($properties['data']);
        $parts = PaginationEnvelope::parts($kind);
        $sent = PaginationEnvelope::sent($kind);
        $restated = [];
        foreach (['links', 'meta'] as $part) {
            if (! array_key_exists($part, $properties)) {
                continue;
            }

            $member = $properties[$part];
            if (! is_array($member) || ($member['type'] ?? null) !== 'object') {
                unset($properties[$part]);

                continue;
            }

            $colliding = self::colliding($member, $sent[$part]);
            if ($colliding === []) {
                continue;
            }

            $restated[$part] = self::mergedPart($parts[$part]['schema'], $sent[$part], $member, $colliding);
            unset($properties[$part]);
        }

        if ($restated !== []) {
            $inline = is_array($envelope['properties'] ?? null) ? $envelope['properties'] : [];
            $page = [...$envelope, 'properties' => [...$inline, ...$restated]];
        }

        if ($properties === []) {
            return $page;
        }

        $members = ['type' => 'object', 'properties' => $properties];
        $stated = is_array($body['required'] ?? null) ? $body['required'] : [];
        $required = array_values(array_filter($stated, static fn (mixed $member): bool => is_string($member) && array_key_exists($member, $properties)));
        if ($required !== []) {
            $members['required'] = $required;
        }

        // Typed, so the declaration states the whole body and takes the inference layer's keywords with it.
        return ['type' => 'object', 'allOf' => [$page, $members]];
    }

    /**
     * The keys of a `with()` part that Laravel's own part also sends — every one of them where the part's
     * keys are not all named, since any could be among them.
     *
     * @param  array<array-key, mixed>  $member
     * @param  array<string, array<string, mixed>>  $sent
     * @return list<string>
     */
    private static function colliding(array $member, array $sent): array
    {
        $open = array_key_exists('patternProperties', $member)
            || (array_key_exists('additionalProperties', $member) && $member['additionalProperties'] !== false);
        if ($open) {
            return array_keys($sent);
        }

        $named = is_array($member['properties'] ?? null) ? array_keys($member['properties']) : [];

        return array_values(array_intersect(array_keys($sent), $named));
    }

    /**
     * A part as `array_merge_recursive` sends it: the page's members and the `with()` part's side by side,
     * and a key both send as the array their two values are merged into — a list or an object, depending
     * on the values, and never the type either states. Where `with()` may not return the key, the page's
     * own value may be sent instead, so either is published.
     *
     * @param  array<string, mixed>  $part
     * @param  array<string, array<string, mixed>>  $sent
     * @param  array<array-key, mixed>  $member
     * @param  list<string>  $colliding
     * @return array<string, mixed>
     */
    private static function mergedPart(array $part, array $sent, array $member, array $colliding): array
    {
        $own = is_array($part['properties'] ?? null) ? $part['properties'] : [];
        $named = is_array($member['properties'] ?? null) ? $member['properties'] : [];
        $stated = array_values(array_filter(
            is_array($member['required'] ?? null) ? $member['required'] : [],
            static fn (mixed $key): bool => is_string($key) && array_key_exists($key, $named),
        ));

        $properties = [...$own, ...$named];
        foreach ($colliding as $key) {
            $merged = PaginationEnvelope::MERGED;
            $properties[$key] = in_array($key, $stated, true) ? $merged : ['anyOf' => [$sent[$key], $merged]];
        }

        // The page sends every key it collides on, whatever `with()` returns.
        $required = array_values(array_unique([...$stated, ...$colliding]));

        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
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
