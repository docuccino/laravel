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
 * OAS object schema. The method's literal return array surfaces from the engine as an
 * {@see ArrayShapeT} (the value types are Larastan-informed — `$this->column` resolves through the
 * resource's model `@mixin`), and each field is converted through the chain.
 *
 * Conditional fields (`whenLoaded`/`when`/`whenNotNull`) return an
 * `Illuminate\Http\Resources\MissingValue` at runtime, so the engine types the value as
 * `T|MissingValue`: the marker makes the property optional and is stripped, folding the wrapped `T`
 * when recoverable (else the property degrades to permissive `{}` + optional).
 *
 * `merge`/`mergeWhen`/`mergeUnless` values are `MergeValue<array{…}>` (via the stub): their array
 * keys are SPLICED into the parent shape rather than nested under a numeric key — optional when the
 * merge was conditional. Several return sites are unioned and nested object shapes recurse this same
 * conditional-aware handling (Wave C items 5–7).
 */
final class ToArrayObject
{
    private const MERGE_VALUE = 'Illuminate\\Http\\Resources\\MergeValue';

    /**
     * Build the object schema for `$fqcn::$method`, or null when the method has no analysable array
     * shape (so the caller can degrade to a bare `{type: object}`).
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

        // The resource method's analysed files are fragment-cache dependencies: editing the resource's
        // toArray (or any file its return shape traced) must invalidate the warm fragment (design §10).
        $context->dependsOn(...$analysis->dependencyFiles);

        // Every non-list array-shape return SITE (a `toArray` with request-dependent branches returns
        // several) is merged, rather than the first-shape-wins that dropped the other branches' keys.
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
     * Merge one or more `toArray` return sites into a single object schema (multi-return-site union).
     * The key set is the union of all sites in first-seen order; a key is `required` only when it is
     * present in EVERY site with no optional/conditional marker anywhere (absent from a site, or a
     * `?key`/`MissingValue` conditional in any site, makes it optional — a key nullable in the
     * required-vs-nullable convention is still required). A key whose converted schema differs across
     * sites becomes an `anyOf` of the distinct site schemas; identical schemas collapse to one.
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
     * Convert one return site's fields to a `key => {schema, optional}` map, stripping the
     * `MissingValue` conditional marker (→ optional) and recursing into nested object shapes so a
     * nested conditional (`'meta' => ['x' => $this->when(...)]`) is stripped there too — the core
     * array mapper does not recurse conditionals.
     *
     * @return array<string, array{schema: array<string, mixed>, optional: bool}>
     */
    private function siteFields(ArrayShapeT $shape, SchemaContext $context): array
    {
        $fields = [];
        foreach ($shape->fields as $field) {
            [$type, $conditional] = self::stripMissing($field->type);

            // A `merge()`/`mergeWhen()` value is a MergeValue whose array shape splices into the parent
            // — its keys become the parent's, not a nested `"0"` property. A falsy mergeWhen unions in
            // MissingValue (stripped above → $conditional), which makes every spliced key optional.
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

            // An unshaped MergeValue (e.g. attributes(), or a dynamic value) cannot be spliced; skip it
            // rather than emit a bogus numeric key, and record the imprecision.
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

    /**
     * The spliceable inner array shape of a `MergeValue<array{…}>` field value, or null when the type
     * is not a MergeValue carrying a constant (non-list) array shape.
     */
    private static function mergeValueShape(DType $type): ?ArrayShapeT
    {
        if (! ($type instanceof ClassT && is_a($type->fqcn, self::MERGE_VALUE, true))) {
            return null;
        }

        $inner = $type->typeArgs[0] ?? null;

        return $inner instanceof ArrayShapeT && ! $inner->isList ? $inner : null;
    }

    /**
     * Convert a field value type, recursing this mapper's conditional-aware handling into a nested
     * (non-list) object shape rather than deferring to the core array mapper — so a `MissingValue`
     * nested in `'meta' => [...]` is stripped, not leaked. Everything else goes through the chain.
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
     * Collapse the per-site schemas recovered for one key: a single distinct schema is emitted as-is,
     * conflicting schemas across return sites become an `anyOf` of the distinct variants (first-seen
     * order, deduped by encoded form for determinism).
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
     * Strip the `MissingValue` marker from a conditional field's type. Returns the recoverable type
     * (the wrapped value when a single member survives, else the original) and whether the marker
     * was present (→ optional).
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

        // A marker was present iff stripping changed the type (a bare `MissingValue` collapses the
        // union to a single survivor; a fully-marker union is returned unchanged by without()).
        $conditional = $stripped->canonicalKey() !== $type->canonicalKey();

        return [$stripped, $conditional];
    }
}
