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
 * Builds a JSON:API `{data: {id, type, attributes?, links?, meta?}}` document, hoisting the resource
 * object to a reusable component via {@see ComponentHoist}. Laravel 13's first-party `JsonApiResource`
 * and the `timacdonald/json-api` base it was upstreamed from expose the same members, so both
 * integrations share this builder. Each mapper holds its own instance — the hoist carries per-mapper
 * recursion state, so there's no shared mutable state between them.
 *
 * `id`/`type` are always `string` per the JSON:API contract rather than analysed; `attributes` and
 * `meta` are analysed from their `to*` methods.
 *
 * Two members are handled specially because a flat `toArray`-style analysis can't see their shapes:
 * - `links`: `toLinks` returns relation-keyed `Link` objects serialising to `{href, meta?}`, so the
 *   shape is emitted directly when the resource overrides the method.
 * - `relationships`, and the `included` compound-document member it drives, are OMITTED. Both packages
 *   express relationships as closures (`'author' => fn () => new AuthorResource(...)`) which the engine
 *   sees as `CallableT`, so nothing here can produce JSON:API's `{data: {type, id}}` linkage object —
 *   emitting either would document a shape the resource never yields.
 */
final class JsonApiDocument
{
    /** A `to*` declared under one of these is the base's own, not a user override. */
    private const JSON_API_BASES = ['TiMacDonald\\', 'Illuminate\\'];

    /**
     * The members analysed from their `to*` methods.
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
        // The component is the resource OBJECT, not the `{data: …}` envelope — that lets a collection
        // reference the bare object per item and wrap once, rather than `{data: [{data: {…}}]}`.
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

        // Only the response root gets the envelope; a collection item or nested relationship stays bare
        // so its enclosing resource applies the single `data` wrap. Mirrors JsonResourceSchema.
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
     * An object of relation-keyed link objects, emitted only when the resource overrides `toLinks`. The
     * relation keys (`self`, `related`, …) are runtime data, hence `additionalProperties`.
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

    /** Whether the resource declares its own `toLinks` rather than inheriting the base's. */
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
