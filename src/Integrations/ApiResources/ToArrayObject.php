<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnionT;
use ReflectionMethod;
use Throwable;

/**
 * Analyses a resource method (`toArray`, or JSON:API's `toAttributes`/`toRelationships`/…) into an
 * object schema. The literal return array surfaces from the engine as an {@see ArrayShapeT} with
 * Larastan-informed value types — `$this->column` resolves through the resource's model `@mixin`.
 *
 * Two Laravel behaviours drive the rest:
 * - `whenLoaded`/`when`/`whenNotNull` return a `MissingValue` at runtime, so the engine types the field
 *   `T|MissingValue`. Stripping the marker makes the property optional and folds `T` when recoverable,
 *   else leaves it permissive `{}`.
 * - `merge`/`mergeWhen`/`mergeUnless` produce a `MergeValue<array{…}>`, whose keys SPLICE into the
 *   parent shape rather than nesting under a numeric key — optional when the merge was conditional.
 *
 * Multiple return sites are unioned, and nested object shapes recurse through the same handling.
 */
final class ToArrayObject
{
    private const MERGE_VALUE = 'Illuminate\\Http\\Resources\\MergeValue';

    /**
     * The object schema for `$fqcn::$method`, or null when there's no analysable array shape — the caller
     * then degrades to a bare `{type: object}`.
     *
     * @return array<string, mixed>|null
     */
    public function analyze(string $fqcn, string $method, SchemaContext $context): ?array
    {
        try {
            $reflection = new ReflectionMethod($fqcn, $method);
        } catch (Throwable) {
            return null;
        }

        if ($reflection->isAbstract()) {
            return null;
        }

        $line = $reflection->getStartLine();
        $analysis = $context->engine()->analyzeAction(new ActionRef(
            (string) $reflection->getFileName(),
            $fqcn,
            $method,
            $line > 0 ? $line : 0,
        ));

        // Editing toArray, or any file its return shape traced, must invalidate the warm fragment.
        $context->dependsOn(...$analysis->dependencyFiles);

        // Merge every non-list return site — a `toArray` with request-dependent branches has several,
        // and first-shape-wins would drop the other branches' keys.
        $shapes = [];
        foreach ($analysis->returns as $return) {
            if ($return->type instanceof ArrayShapeT && ! $return->type->isList) {
                $shapes[] = $return->type;
            }
        }

        if ($shapes === []) {
            return null;
        }

        return $this->mergeShapes($shapes, $context);
    }

    /**
     * Merges return sites into one object schema. Keys are the union of all sites in first-seen order; a
     * key is required only when every site has it with no optional/conditional marker anywhere (nullable
     * is still required — that's the convention). A key whose schema differs across sites becomes an
     * `anyOf` of the distinct variants.
     *
     * @param  list<ArrayShapeT>  $shapes
     * @return array<string, mixed>
     */
    private function mergeShapes(array $shapes, SchemaContext $context): array
    {
        $siteCount = count($shapes);

        /** @var array<string, array{schemas: list<array<string, mixed>>, present: int, optional: bool}> $merged */
        $merged = [];
        foreach ($shapes as $shape) {
            foreach ($this->siteFields($shape, $context) as $key => $field) {
                $merged[$key] ??= ['schemas' => [], 'present' => 0, 'optional' => false];
                $merged[$key]['present']++;
                $merged[$key]['optional'] = $merged[$key]['optional'] || $field['optional'];
                $merged[$key]['schemas'][] = $field['schema'];
            }
        }

        $properties = [];
        $required = [];
        foreach ($merged as $key => $info) {
            $properties[$key] = self::combine($info['schemas']);
            if (! $info['optional'] && $info['present'] === $siteCount) {
                $required[] = $key;
            }
        }

        $object = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $object['required'] = $required;
        }

        return $object;
    }

    /**
     * One return site's fields as `key => {schema, optional}`, with `MissingValue` stripped. Recurses
     * into nested shapes because the core array mapper doesn't handle conditionals — without this a
     * `'meta' => ['x' => $this->when(...)]` would leak the marker.
     *
     * @return array<string, array{schema: array<string, mixed>, optional: bool}>
     */
    private function siteFields(ArrayShapeT $shape, SchemaContext $context): array
    {
        $fields = [];
        foreach ($shape->fields as $field) {
            [$type, $conditional] = self::stripMissing($field->type);

            // A MergeValue's keys become the parent's, not a nested `"0"` property. A falsy mergeWhen
            // unions in MissingValue (stripped above), which makes every spliced key optional.
            $inner = self::mergeValueShape($type);
            if ($inner !== null) {
                foreach ($this->siteFields($inner, $context) as $key => $spliced) {
                    $fields[$key] = [
                        'schema' => $spliced['schema'],
                        'optional' => $spliced['optional'] || $conditional,
                    ];
                }

                continue;
            }

            // An unshaped MergeValue (attributes(), a dynamic value) can't be spliced — skip it rather
            // than emit a bogus numeric key, and record the imprecision.
            if ($type instanceof ClassT && is_a($type->fqcn, self::MERGE_VALUE, true)) {
                $context->lowerConfidence(0.8);

                continue;
            }

            $fields[(string) $field->key] = [
                'schema' => $this->convertValue($type, $context),
                'optional' => $field->optional || $conditional,
            ];
        }

        return $fields;
    }

    /** A `MergeValue<array{…}>`'s spliceable inner shape, or null when it carries no constant shape. */
    private static function mergeValueShape(DType $type): ?ArrayShapeT
    {
        if (! ($type instanceof ClassT && is_a($type->fqcn, self::MERGE_VALUE, true))) {
            return null;
        }

        $inner = $type->typeArgs[0] ?? null;

        return $inner instanceof ArrayShapeT && ! $inner->isList ? $inner : null;
    }

    /**
     * A field value's schema. Nested non-list shapes recurse here rather than through the core array
     * mapper, so their conditionals are stripped too; everything else goes through the chain.
     *
     * @return array<string, mixed>
     */
    private function convertValue(DType $type, SchemaContext $context): array
    {
        if ($type instanceof ArrayShapeT && ! $type->isList) {
            return $this->mergeShapes([$type], $context);
        }

        return $context->convert($type);
    }

    /**
     * One key's per-site schemas collapsed: a single distinct schema as-is, otherwise an `anyOf` of the
     * distinct variants, deduped by encoded form in first-seen order so output stays deterministic.
     *
     * @param  list<array<string, mixed>>  $schemas
     * @return array<string, mixed>
     */
    private static function combine(array $schemas): array
    {
        $distinct = [];
        foreach ($schemas as $schema) {
            $distinct[(string) json_encode($schema)] = $schema;
        }
        $distinct = array_values($distinct);

        return count($distinct) === 1 ? $distinct[0] : ['anyOf' => $distinct];
    }

    /**
     * `[type, wasConditional]` — the `MissingValue` marker stripped off a conditional field's type.
     *
     * @return array{0: DType, 1: bool}
     */
    private static function stripMissing(DType $type): array
    {
        if (! $type instanceof UnionT) {
            return [$type, false];
        }

        $stripped = $type->without(
            static fn (DType $member): bool => $member instanceof ClassT && is_a($member->fqcn, ResourceReflector::MISSING_VALUE, true),
        );

        // The marker was present iff stripping changed the type. A union of nothing but markers comes
        // back unchanged from without(), so that case reads as non-conditional.
        $conditional = $stripped->canonicalKey() !== $type->canonicalKey();

        return [$stripped, $conditional];
    }
}
