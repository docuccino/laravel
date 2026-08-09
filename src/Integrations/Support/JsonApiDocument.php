<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Laravel\Integrations\ApiResources\ToArrayObject;
use ReflectionMethod;
use Throwable;

/**
 * The shared JSON:API resource-object document builder. A JSON:API resource — whether Laravel 13's
 * first-party `JsonApiResource` or the pre-13 `timacdonald/json-api` base it was upstreamed from —
 * exposes the same member surface, so both integrations feed this one builder rather than
 * duplicating the document shape. It emits `{data: {id, type, attributes?, links?, meta?}}`, hoisting
 * the resource to a reusable component (including the self-reference cycle-break) via
 * {@see ComponentHoist}.
 *
 * `id` and `type` are emitted as `string` unconditionally (the JSON:API contract), not analysed from
 * `toId`/`toType`; `attributes`, `links` and `meta` ARE analysed from their `to*` methods into object
 * schemas ({@see ToArrayObject}).
 *
 * `relationships` — and the top-level `included` compound-document member it drives — are deliberately
 * OMITTED. Both packages express relationships as closures (`'author' => fn () => new AuthorResource(...)`),
 * which the type engine reports as `CallableT` — a flat `toArray`-style analysis of `toRelationships`
 * cannot produce JSON:API's `{data: {type, id}}` linkage object, so emitting either it or the
 * `included` array of resolved relations would document a shape the resource never yields. Both are
 * left out until the linkage object can be modelled from real relationship resolution.
 *
 * `links` is special-cased rather than analysed: `toLinks` returns `Link` objects (keyed by relation),
 * each serialising to `{href, meta?}`, so a flat `toArray` analysis cannot see the shape. When the
 * resource overrides `toLinks`, an object of link objects is emitted.
 *
 * Each schema mapper holds its OWN instance (the hoist carries per-mapper recursion state), so there
 * is no shared mutable state between the first-party and timacdonald mappers.
 */
final class JsonApiDocument
{
    /** JSON:API bases whose `toLinks`/`to*` are not user overrides (links/attributes fallbacks). */
    private const JSON_API_BASES = ['TiMacDonald\\', 'Illuminate\\'];

    /**
     * The JSON:API resource-object members analysed from their `to*` methods (links special-cased,
     * relationships omitted; see the class docblock).
     *
     * @var array<string, string>
     */
    private const MEMBERS = [
        'attributes' => 'toAttributes',
        'meta' => 'toMeta',
    ];

    public function __construct(
        private readonly ToArrayObject $toArray = new ToArrayObject,
        private readonly ComponentHoist $hoist = new ComponentHoist,
    ) {}

    public function build(ClassT $type, SchemaContext $context): SchemaResult
    {
        // The hoisted component is the JSON:API resource OBJECT (`{id, type, attributes?, links?}`),
        // NOT the `{data: …}` document envelope. That way a collection can reference the bare object
        // per item and wrap the envelope once around the array, instead of the old double-wrapped
        // `{data: [{data: {…}}]}`.
        $object = $this->hoist->hoist($context, $type->fqcn, function () use ($type, $context): array {
            $data = [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                ],
                'required' => ['id', 'type'],
            ];

            foreach (self::MEMBERS as $member => $method) {
                $analyzed = $this->toArray->analyze($type->fqcn, $method, $context);
                if ($analyzed !== null && ($analyzed['properties'] ?? []) !== []) {
                    $data['properties'][$member] = $analyzed;
                }
            }

            $links = self::linksSchema($type->fqcn);
            if ($links !== null) {
                $data['properties']['links'] = $links;
            }

            return $data;
        });

        // The document envelope wraps the resource object ONLY at the response root (depth 1). A
        // resource reached as a collection item or a nested relationship stays the bare object, so
        // its enclosing collection/resource applies the single `data` wrap (mirrors the depth-gating
        // in JsonResourceSchema::wrapTopLevel).
        if ($context->depth() !== 1) {
            return $object;
        }

        return new SchemaResult([
            'type' => 'object',
            'properties' => ['data' => $object->schema],
            'required' => ['data'],
        ], $object->confidence);
    }

    /**
     * The `links` member schema — an object of relation-keyed link objects (`{href, meta?}`) —
     * emitted only when the resource overrides `toLinks`. The relation keys (`self`, `related`, …) are
     * runtime data, so they are captured via `additionalProperties` rather than enumerated.
     *
     * @return array<string, mixed>|null
     */
    private static function linksSchema(string $fqcn): ?array
    {
        if (! self::overridesLinks($fqcn)) {
            return null;
        }

        return [
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'object',
                'properties' => [
                    'href' => ['type' => 'string'],
                    'meta' => ['type' => 'object'],
                ],
                'required' => ['href'],
            ],
        ];
    }

    /** Whether the resource declares its own `toLinks` (not the untouched JSON:API base method). */
    private static function overridesLinks(string $fqcn): bool
    {
        if (! class_exists($fqcn) || ! method_exists($fqcn, 'toLinks')) {
            return false;
        }

        try {
            $declaring = (new ReflectionMethod($fqcn, 'toLinks'))->getDeclaringClass()->getName();
        } catch (Throwable) {
            return false;
        }

        foreach (self::JSON_API_BASES as $base) {
            if (str_starts_with($declaring, $base)) {
                return false;
            }
        }

        return true;
    }
}
