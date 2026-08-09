<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Laravel\Integrations\Support\SpatieDataEnvelope;

/**
 * Maps a `spatie/laravel-data` Data class (and its collections) to an OAS schema, superseding the
 * core class mapper for Data types (it runs earlier in the chain). A single Data class hoists to a
 * reusable component — named by `#[SchemaName]` (else the short class name) and pinned by
 * `#[SchemaId]` (else the FQCN) for diff identity — whose properties come from the engine's
 * {@see ClassMetadata} refined by the reflected spatie presentation facts:
 *
 * - `#[Hidden]` (spatie's or ours, property- or class-level) drops the property.
 * - `#[MapOutputName]`/`#[MapName]` renames the output key.
 * - an `Optional`/`Lazy` marker in the property type makes it non-required (and the marker is stripped
 *   from the rendered type).
 * - a nested Data property recurses through the chain back into this mapper (self-reference is
 *   cycle-broken via the reserved component name, mirroring the core class mapper).
 *
 * A `DataCollection` renders as an array of its item schema; the paginated variants
 * (`PaginatedDataCollection`/`CursorPaginatedDataCollection`) render spatie's OWN paginator envelope
 * ({@see SpatieDataEnvelope}) — `links` as an array of `{url,label,active}`, meta with the `*_page_url`
 * members — which diverges from Laravel's resource envelope.
 *
 * At the RESPONSE ROOT (`{@see SchemaContext::depth()}` === 1) a wrap key ({@see WrapResolver} —
 * class `defaultWrap()` else global `config('data.wrap')`) nests the payload under that key
 * (`{ data: <schema> }`); a nested Data property is never wrapped so its shared `$ref` stays wrap-free.
 * A paginated collection is always wrapped, so its envelope items key IS the wrap key — never an
 * extra outer wrap (spatie's `PaginatedCollectionIsAlwaysWrapped`).
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class DataSchema implements TypeToSchema
{
    /**
     * @param  string  $dateFormat  the app's `data.date_format` (a PHP date() format), used to decide
     *                              a `DateTimeInterface` property's OAS `format` (date vs date-time)
     */
    public function __construct(
        private readonly DataClassReflector $reflector = new DataClassReflector,
        private readonly ComponentHoist $hoist = new ComponentHoist,
        private readonly string $dateFormat = 'Y-m-d\TH:i:sP',
        private readonly WrapResolver $wrap = new WrapResolver,
    ) {}

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT
            && (DataClassReflector::isData($type->fqcn) || DataClassReflector::isDataCollection($type->fqcn));
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        if (DataClassReflector::isDataCollection($type->fqcn)) {
            return $this->collection($type, $context);
        }

        return $this->wrapRoot($this->object($type, $context), $type->fqcn, $context);
    }

    /**
     * Wrap a top-level Data object under its wrap key ({@see WrapResolver}); a nested object
     * ({@see SchemaContext::depth()} > 1) or an unwrapped document is returned unchanged.
     */
    private function wrapRoot(SchemaResult $result, string $fqcn, SchemaContext $context): SchemaResult
    {
        if ($context->depth() !== 1) {
            return $result;
        }

        $key = $this->wrap->key($fqcn);
        if ($key === null) {
            return $result;
        }

        return new SchemaResult([
            'type' => 'object',
            'properties' => [$key => $result->schema],
            'required' => [$key],
        ], $result->confidence);
    }

    private function object(ClassT $type, SchemaContext $context): SchemaResult
    {
        $fqcn = $type->fqcn;
        $facts = $this->reflector->classFacts($fqcn);
        $metadata = $context->engine()->classMetadata(new ClassRef($fqcn));

        // The Data class's reflected shape is a fragment-cache dependency (design §10): editing a
        // property type / #[Hidden] / MapName must invalidate the warm fragment.
        $context->dependsOn(...$metadata->dependencyFiles);

        // An unexpandable Data class degrades to a bare object without reserving a component name —
        // there is no body, so nothing self-references it.
        if ($metadata->properties === []) {
            return new SchemaResult(['type' => 'object'], 0.4);
        }

        return $this->hoist->hoist($context, $fqcn, function () use ($fqcn, $facts, $metadata, $context): array {
            $properties = [];
            $required = [];
            foreach ($metadata->properties as $property) {
                if (in_array($property->name, $facts['hidden'], true) || $this->reflector->isPropertyHidden($fqcn, $property->name)) {
                    continue;
                }

                $clean = self::stripMarkers($property->type);
                $schema = $this->propertySchema($fqcn, $property->name, $clean, $context);
                if ($property->summary !== null) {
                    $schema['description'] = $property->summary;
                }
                if ($property->example !== null) {
                    $schema['example'] = $property->example;
                }
                $default = $this->reflector->propertyDefault($fqcn, $property->name);
                if ($default['hasDefault'] && $default['value'] !== null) {
                    $schema['default'] = $default['value'];
                }

                $key = $this->reflector->outputName($fqcn, $property->name);
                $properties[$key] = $schema;

                // A Data property spatie always emits is required even when nullable: the key is on
                // the wire carrying `null`, so nullability lives in the VALUE's type union, not in
                // presence. Only an `Optional`/`Lazy` marker (stripped above) makes it non-required
                // (cross-mapper required-vs-nullable convention — matches ModelSchema/ToArrayObject).
                if (! $this->reflector->isPropertyOptional($fqcn, $property->name)) {
                    $required[] = $key;
                }
            }

            $object = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $object['required'] = $required;
            }

            return $object;
        }, $facts['schemaName'], $facts['schemaId']);
    }

    /**
     * The schema fragment for one property, special-casing the two shapes the generic class mapper
     * gets wrong: a `#[DataCollectionOf(X)]` collection (→ array of X) and a `DateTimeInterface`
     * property (→ a formatted string, not a bare object).
     *
     * @return array<string, mixed>
     */
    private function propertySchema(string $fqcn, string $property, DType $clean, SchemaContext $context): array
    {
        $item = $this->reflector->dataCollectionOf($fqcn, $property);
        if ($item !== null) {
            return ['type' => 'array', 'items' => $context->convert(new ClassT($item))];
        }

        // A `#[WithCast(DateTimeInterfaceCast::class, format: 'U')]` serialises the datetime as a Unix
        // timestamp integer — not the default date-time string. Only the numeric `U` format changes
        // the type; other explicit formats still render as a date/date-time string below.
        if ($this->reflector->dateTimeCastFormat($fqcn, $property) === 'U') {
            return ['type' => 'integer', 'description' => 'Unix timestamp (seconds).'];
        }

        if ($this->isDateTime($clean)) {
            return ['type' => 'string', 'format' => $this->dateFormatToOas()];
        }

        return $context->convert($clean);
    }

    /** Whether the (marker-stripped) type is, or unions in, a `DateTimeInterface`. */
    private function isDateTime(DType $clean): bool
    {
        if ($clean instanceof ClassT) {
            return DataClassReflector::isDateTime($clean->fqcn);
        }

        if ($clean instanceof UnionT) {
            foreach ($clean->members as $member) {
                if ($member instanceof ClassT && DataClassReflector::isDateTime($member->fqcn)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** date-only `data.date_format` → `date`; a format bearing time/zone tokens → `date-time`. */
    private function dateFormatToOas(): string
    {
        return preg_match('/[GHhisuveTPOaA]/', $this->dateFormat) === 1 ? 'date-time' : 'date';
    }

    private function collection(ClassT $type, SchemaContext $context): SchemaResult
    {
        // Spatie's collection generics are `@template TKey of array-key, @template TValue` — so the
        // ITEM type is the LAST arg. `PaginatedDataCollection<int, AuthorData>` (the shape spatie's
        // own generics + the docblock parser produce) carries [TKey=int, TValue=AuthorData]; reading
        // typeArgs[0] there would document the items as `{type: integer}`.
        $item = DataClassReflector::collectionValueType($type);
        $items = $item !== null ? $context->convert($item) : [];

        // A paginated collection is ALWAYS wrapped: the items key IS the wrap key (global ?? 'data'),
        // and the {items,links,meta} envelope is never additionally nested under an outer wrap.
        $kind = $this->reflector->collectionKind($type->fqcn);
        if ($kind === 'length' || $kind === 'cursor') {
            $dataKey = $this->wrap->key(null) ?? 'data';
            $schema = $kind === 'length'
                ? SpatieDataEnvelope::length($items, $dataKey)
                : SpatieDataEnvelope::cursor($items, $dataKey);

            return new SchemaResult($schema, 0.9);
        }

        // A plain DataCollection is a bare array of items, wrapped under the global key only at the
        // response root (a nested collection property stays a bare array).
        $schema = ['type' => 'array', 'items' => $items];
        $key = $context->depth() === 1 ? $this->wrap->key(null) : null;
        if ($key !== null) {
            $schema = ['type' => 'object', 'properties' => [$key => $schema], 'required' => [$key]];
        }

        return new SchemaResult($schema, 0.9);
    }

    /** Drop spatie `Optional`/`Lazy` markers from a union so only the real type is rendered. */
    public static function stripMarkers(DType $type): DType
    {
        if (! $type instanceof UnionT) {
            return $type;
        }

        return $type->without(static fn (DType $member): bool => $member instanceof ClassT
            && (is_a($member->fqcn, DataClassReflector::OPTIONAL, true) || is_a($member->fqcn, DataClassReflector::LAZY, true)));
    }
}
