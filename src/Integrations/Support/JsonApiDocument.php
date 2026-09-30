<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Laravel\Integrations\ApiResources\ResourceReflector;
use Docuccino\Laravel\Integrations\ApiResources\ToArrayObject;
use Docuccino\Laravel\Integrations\TimacdonaldJsonApi\TimacdonaldResourceReflector;
use ReflectionMethod;
use Throwable;

/**
 * Builds a JSON:API `{data: {id, type, attributes?, links?, meta?}, included?, jsonapi?}` document,
 * hoisting the resource object to a reusable component via {@see ComponentHoist}. Laravel's first-party
 * `JsonApiResource` (12.45 and later) and the `timacdonald/json-api` base it was upstreamed from expose
 * the same members, so both integrations share this builder. Each mapper holds its own instance — the hoist carries per-mapper
 * recursion state, so there's no shared mutable state between them.
 *
 * `id`/`type` are always `string` per the JSON:API contract rather than analysed; `attributes` and
 * `meta` are analysed from their `to*` methods.
 *
 * Two members are handled specially:
 * - `links`: Laravel sends what `toLinks` returns, so it is analysed like `meta`; timacdonald's returns
 *   `Link` objects it keys by relation, each serialising to `{href, meta?}`, so that shape is emitted.
 *   Either only where the resource overrides the method.
 * - `relationships` is OMITTED. Both packages express relationships as closures (`'author' => fn () =>
 *   new AuthorResource(...)`) which the engine sees as `CallableT`, so nothing here can produce JSON:API's
 *   `{data: {type, id}}` linkage object. The document's `included` member is any resource object, so it
 *   is published with the other top-level members ({@see JsonApiTopLevel}).
 *
 * A timacdonald resource whose installed code does not send its resource object
 * ({@see TimacdonaldResourceReflector::sentOtherwise()}) is published as an open object, with a diagnostic.
 */
final class JsonApiDocument
{
    /** A `to*` declared under one of these is the base's own, not a user override. */
    private const JSON_API_BASES = ['TiMacDonald\\', 'Illuminate\\'];

    /**
     * The members analysed from their `to*` methods, and whether the package filters conditional values
     * out of what it returns — both filter attributes, and both send meta as returned.
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const MEMBERS = [
        'attributes' => ['toAttributes', true],
        'meta' => ['toMeta', false],
    ];

    public function __construct(
        private readonly ToArrayObject $toArray = new ToArrayObject,
        private readonly ComponentHoist $hoist = new ComponentHoist,
    ) {}

    public function build(ClassT $type, SchemaContext $context): SchemaResult
    {
        // The component is the resource OBJECT, not the `{data: …}` envelope — that lets a collection
        // reference the bare object per item and wrap once, rather than `{data: [{data: {…}}]}`.
        $object = $this->hoist->hoist($context, $type->fqcn, function () use ($type, $context): ?array {
            $sender = TimacdonaldResourceReflector::isResource($type->fqcn) ? TimacdonaldResourceReflector::sentOtherwise($type->fqcn) : null;
            if ($sender !== null) {
                $context->diagnostic(TimacdonaldResourceReflector::notSent($type->fqcn, $sender));

                return null;
            }

            $data = [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                ],
                'required' => ['id', 'type'],
            ];

            foreach (self::MEMBERS as $member => [$method, $filtered]) {
                $analyzed = $this->toArray->analyze($type->fqcn, $method, $context, $filtered);
                if ($analyzed !== null && ($analyzed['properties'] ?? []) !== []) {
                    $data['properties'][$member] = $analyzed;
                }
            }

            $links = $this->linksSchema($type->fqcn, $context);
            if ($links !== null) {
                $data['properties']['links'] = $links;
            }

            return $data;
        });

        // Only the response root gets the envelope; a collection item or nested relationship stays bare
        // so its enclosing resource applies the single `data` wrap. Mirrors JsonResourceSchema.
        if (! $context->atRoot()) {
            return $object;
        }

        // The members the family's `with()` adds beside `data`, which a static configured at boot decides.
        $members = JsonApiTopLevel::members($type->fqcn, $context) ?? ['properties' => [], 'required' => []];
        $context->dependsOn(...DeclarationFiles::of($type->fqcn));

        return new SchemaResult([
            'type' => 'object',
            'properties' => ['data' => $object->schema, ...$members['properties']],
            'required' => ['data', ...$members['required']],
        ], $object->confidence);
    }

    /**
     * The `links` member, only where the resource overrides `toLinks`: Laravel's as analysed, timacdonald's
     * an object of relation-keyed link objects whose keys (`self`, `related`, …) are runtime data.
     *
     * @return array<string, mixed>|null
     */
    private function linksSchema(string $fqcn, SchemaContext $context): ?array
    {
        if (! self::overridesLinks($fqcn)) {
            return null;
        }

        if (is_a($fqcn, ResourceReflector::JSON_API_RESOURCE, true)) {
            $analyzed = $this->toArray->analyze($fqcn, 'toLinks', $context, false);

            return $analyzed !== null && ($analyzed['properties'] ?? []) !== [] ? $analyzed : null;
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
