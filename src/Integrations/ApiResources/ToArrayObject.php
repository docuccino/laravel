<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\VoidT;
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
 * Both hold only where Laravel filters the array, as `resolve()` does for `toArray`; `with()` is merged as
 * returned, so an unfiltered reading keeps the marker's key and gives up on a merge. Multiple return
 * sites are unioned — a key is required only where every site, readable or not, returns it — and nested
 * object shapes recurse through the same handling.
 */
final class ToArrayObject
{
    private const MERGE_VALUE = 'Illuminate\\Http\\Resources\\MergeValue';

    /** An empty PHP array, which `json_encode` sends as `[]` — never `{}`. */
    private const EMPTY_ARRAY = ['type' => 'array', 'maxItems' => 0];

    /** A `MissingValue` sent unfiltered: an object with no public properties encodes as `{}`. */
    private const MISSING = ['type' => 'object', 'maxProperties' => 0];

    /**
     * The object schema for `$fqcn::$method`, or null when no return site has an analysable array shape —
     * the caller then degrades to a bare `{type: object}`. Filtered as Laravel filters it unless
     * `$filtered` is false, and the whole array's own emptiness is the caller's to publish.
     *
     * @return array<string, mixed>|null
     */
    public function analyze(string $fqcn, string $method, SchemaContext $context, bool $filtered = true): ?array
    {
        return $this->read($fqcn, $method, $context, $filtered)['object'] ?? null;
    }

    /**
     * `toArray` read as `resolve()` sends it on its own: the object, and whether it may be sent as `[]`
     * because every key can be absent — a site returning none, or one whose keys are all conditional. A
     * site that cannot be read is not assumed empty.
     *
     * @return array{object: array<string, mixed>, mayBeEmpty: bool}|null
     */
    public function analyzeBody(string $fqcn, SchemaContext $context): ?array
    {
        return $this->read($fqcn, 'toArray', $context, true);
    }

    /**
     * An object schema that may also be sent as the empty array `[]`.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    public static function orEmpty(array $object): array
    {
        return ['anyOf' => [$object, self::EMPTY_ARRAY]];
    }

    /**
     * @return array{object: array<string, mixed>, mayBeEmpty: bool}|null
     */
    private function read(string $fqcn, string $method, SchemaContext $context, bool $filtered): ?array
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

        // Merge every return site — a `toArray` with request-dependent branches has several, and
        // first-shape-wins would drop the other branches' keys. A site with no readable shape still
        // counts: it may omit any key, so no key is required once one is present.
        $sites = [];
        $unread = 0;
        foreach ($analysis->returns as $return) {
            foreach ($return->type instanceof UnionT ? $return->type->members : [$return->type] as $type) {
                $fields = self::isObjectShape($type) ? $this->siteFields($type, $context, $filtered) : null;
                if ($fields !== null) {
                    $sites[] = $fields;
                } elseif (! $type instanceof NeverT && ! $type instanceof VoidT) {
                    $unread++;
                }
            }
        }

        if ($sites === []) {
            return null;
        }

        if ($unread > 0) {
            $context->lowerConfidence(0.8);
        }

        return self::mergeSites($sites, count($sites) + $unread);
    }

    /**
     * A shape whose keys can be read: a keyed array, or `[]`, which carries none of them. PHP types an
     * empty array as a list, so the empty case is admitted by its fields rather than by `isList`.
     *
     * @phpstan-assert-if-true ArrayShapeT $type
     */
    private static function isObjectShape(DType $type): bool
    {
        return $type instanceof ArrayShapeT && (! $type->isList || $type->fields === []);
    }

    /**
     * Merges return sites into one object schema. Keys are the union of all sites in first-seen order; a
     * key is required only when every site has it with no optional/conditional marker anywhere (nullable
     * is still required — that's the convention). A key whose schema differs across sites becomes an
     * `anyOf` of the distinct variants. The object may be empty when some site can drop every key.
     *
     * @param  list<array<string, array{schema: array<string, mixed>, optional: bool}>>  $sites
     * @param  int  $siteCount  every site the shapes came from, readable or not
     * @return array{object: array<string, mixed>, mayBeEmpty: bool}
     */
    private static function mergeSites(array $sites, int $siteCount): array
    {
        /** @var array<string, array{schemas: list<array<string, mixed>>, present: int, optional: bool}> $merged */
        $merged = [];
        $mayBeEmpty = false;
        foreach ($sites as $fields) {
            $mayBeEmpty = $mayBeEmpty || array_filter($fields, static fn (array $field): bool => ! $field['optional']) === [];
            foreach ($fields as $key => $field) {
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

        return ['object' => $object, 'mayBeEmpty' => $mayBeEmpty];
    }

    /**
     * One return site's fields as `key => {schema, optional}`, with `MissingValue` stripped when filtered.
     * Recurses into nested shapes because the core array mapper doesn't handle conditionals — without
     * this a `'meta' => ['x' => $this->when(...)]` would leak the marker. Null when unfiltered and the
     * site holds a merge, whose value is then sent under a numeric key rather than spliced.
     *
     * @return array<string, array{schema: array<string, mixed>, optional: bool}>|null
     */
    private function siteFields(ArrayShapeT $shape, SchemaContext $context, bool $filtered): ?array
    {
        $fields = [];
        foreach ($shape->fields as $field) {
            [$type, $conditional] = self::stripMissing($field->type);
            $isMerge = $type instanceof ClassT && is_a($type->fqcn, self::MERGE_VALUE, true);

            if (! $filtered) {
                if ($isMerge) {
                    return null;
                }

                $schema = $this->convertValue($type, $context, false);
                $fields[(string) $field->key] = [
                    'schema' => $conditional ? self::combine([$schema, self::MISSING]) : $schema,
                    'optional' => $field->optional,
                ];

                continue;
            }

            // A MergeValue's keys become the parent's, not a nested `"0"` property. A falsy mergeWhen
            // unions in MissingValue (stripped above), which makes every spliced key optional.
            $inner = self::mergeValueShape($type);
            if ($inner !== null) {
                foreach ($this->siteFields($inner, $context, true) ?? [] as $key => $spliced) {
                    $fields[$key] = [
                        'schema' => $spliced['schema'],
                        'optional' => $spliced['optional'] || $conditional,
                    ];
                }

                continue;
            }

            // An unshaped MergeValue (attributes(), a dynamic value) can't be spliced — skip it rather
            // than emit a bogus numeric key, and record the imprecision.
            if ($isMerge) {
                $context->lowerConfidence(0.8);

                continue;
            }

            $fields[(string) $field->key] = [
                'schema' => $this->convertValue($type, $context, true),
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
     * mapper, so their conditionals are stripped too — and a filtered one whose keys can all be dropped
     * is sent as `[]`; everything else goes through the chain, an object's shape included, since Laravel
     * filters arrays only.
     *
     * @return array<string, mixed>
     */
    private function convertValue(DType $type, SchemaContext $context, bool $filtered): array
    {
        if (! ($type instanceof ArrayShapeT && ! $type->isList && ! $type->isObject)) {
            return $context->convert($type);
        }

        $fields = $this->siteFields($type, $context, $filtered);
        if ($fields === null) {
            $context->lowerConfidence(0.8);

            return ['type' => 'object'];
        }

        $nested = self::mergeSites([$fields], 1);

        return $filtered && $nested['mayBeEmpty'] ? self::orEmpty($nested['object']) : $nested['object'];
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
