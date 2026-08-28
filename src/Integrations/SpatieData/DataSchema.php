<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Schema\DocumentedExamples;
use Docuccino\Core\Extensions\Schema\MockHints;
use Docuccino\Core\Extensions\Schema\PropertyAnnotations;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Extensions\Schema\SchemaUnion;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;
use Docuccino\Laravel\Integrations\Support\PageComponent;
use Docuccino\Laravel\Integrations\Support\PaginationParts;
use Docuccino\Laravel\Integrations\Support\SpatieDataEnvelope;

/**
 * Maps a `spatie/laravel-data` Data class and its collections to a schema, superseding the core class
 * mapper for Data types by running earlier in the chain. A Data class hoists to a reusable component,
 * named by `#[SchemaName]` (else the short class name) and pinned by `#[SchemaId]` (else the FQCN) for
 * diff identity, with properties from the engine's {@see ClassMetadata} refined by the reflected spatie
 * facts: `#[Hidden]` drops, `#[MapOutputName]`/`#[MapName]` renames, an `Optional`/`Lazy` marker makes
 * the property non-required and is stripped from the rendered type. Nested Data recurses through the
 * chain, cycle-broken via the reserved component name.
 *
 * The two vendor quirks that matter:
 * - the paginated variants render spatie's OWN envelope ({@see SpatieDataEnvelope}), not Laravel's
 *   resource envelope — `links` is an array of `{url,label,active}` and meta carries `*_page_url`.
 * - a paginated collection is always wrapped (`PaginatedCollectionIsAlwaysWrapped`), so its envelope's
 *   items key IS the wrap key; it never picks up a second outer wrap. That envelope hoists to a
 *   shared component of its own ({@see PageComponent}), one per Data class and kind.
 *
 * Wrapping otherwise applies only at the response root ({@see SchemaContext::atRoot()}), from
 * {@see WrapResolver} — so a nested Data property's shared `$ref` stays wrap-free. A root union is
 * still the root on every arm, so each arm carries the envelope its own class resolves.
 *
 * That root-only rule is a decision about the DOCUMENT, not a claim about the runtime: spatie unwraps
 * a nested single Data object but re-wraps a nested COLLECTION, so a global `data.wrap` puts one on
 * the wire that this schema does not describe. The shape is not modelled because a `#[WithTransformer]`
 * replaces serialisation and cannot be read statically — a component is shared, and one caller's
 * envelope would travel to every other. {@see NestedCollectionWrap} tells the author instead.
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class DataSchema implements TypeToSchema
{
    private readonly NestedCollectionWrap $nestedWrap;

    /**
     * @param  string  $dateFormat  the app's `data.date_format` (a PHP date() format), read through
     *                              {@see DateWireFormat} — the same reading the request side gives it,
     *                              so a `DateTimeInterface` property documents one format both ways
     */
    public function __construct(
        private readonly DataClassReflector $reflector = new DataClassReflector,
        private readonly ComponentHoist $hoist = new ComponentHoist,
        private readonly string $dateFormat = DateWireFormat::DEFAULT_FORMAT,
        private readonly WrapResolver $wrap = new WrapResolver,
    ) {
        $this->nestedWrap = new NestedCollectionWrap($this->reflector, $this->wrap);
    }

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

    /** Wraps a root Data object under its wrap key; nested or unwrapped results pass through. */
    private function wrapRoot(SchemaResult $result, string $fqcn, SchemaContext $context): SchemaResult
    {
        if (! $context->atRoot()) {
            return $result;
        }

        // `withoutWrapping()` and `defaultWrap()` are both inheritance-answered, so the envelope can be
        // decided in a file this class does not name.
        $context->dependsOn(...DeclarationFiles::of($fqcn));

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

        // Editing a property type, #[Hidden] or MapName must invalidate the warm fragment.
        $context->dependsOn(...$metadata->dependencyFiles);

        // An unexpandable Data class degrades to a bare object without reserving a component name —
        // there's no body, so nothing can self-reference it.
        if ($metadata->properties === []) {
            return new SchemaResult(['type' => 'object'], 0.4);
        }

        return $this->hoist->hoist($context, $fqcn, function () use ($fqcn, $facts, $metadata, $context): array {
            $properties = [];
            $required = [];
            $keys = [];
            // Every name the deny-list was weighed against, the hidden ones included — a Data class hides
            // by the PROPERTY's name, so this is the property list and not the mapped keys below.
            $considered = [];
            foreach ($metadata->properties as $property) {
                $considered[] = $property->name;

                if (in_array($property->name, $facts['hidden'], true) || $this->reflector->isPropertyHidden($fqcn, $property->name)) {
                    continue;
                }

                $clean = self::stripMarkers($property->type);
                $schema = $this->propertySchema($fqcn, $property->name, $clean, $context);
                if ($property->summary !== null) {
                    $schema['description'] = $property->summary;
                }
                $default = $this->reflector->propertyDefault($fqcn, $property->name);
                if ($default['hasDefault'] && $default['value'] !== null) {
                    $schema['default'] = $default['value'];
                }

                $key = $this->reflector->outputName($fqcn, $property->name);
                $keys[$property->name] = $key;
                $properties[$key] = $schema;

                // Required even when nullable — spatie still emits the key carrying `null`, so
                // nullability lives in the value's type union. Only an Optional/Lazy marker changes this.
                if (! $this->reflector->isPropertyOptional($fqcn, $property->name)) {
                    $required[] = $key;
                }
            }

            foreach (SchemaIdentity::unmatchedHidden($fqcn, $considered) as $diagnostic) {
                $context->diagnostic($diagnostic);
            }

            $object = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $object['required'] = $required;
            }

            // #[MapName] can rename a property on the wire, so a declaration follows the property to
            // whatever key it publishes under — the docblock example (30) first, so the attribute (40)
            // beats it, as the description written in the loop above is beaten.
            $object = DocumentedExamples::applyTo($context, $object, $fqcn, $metadata->properties, $keys);
            $object = PropertyAnnotations::applyTo($context, $object, $fqcn, $keys);

            return MockHints::applyTo($context, $object, $fqcn, $keys);
        }, $facts['schemaName'], $facts['schemaId']);
    }

    /**
     * One property's schema, special-casing the two shapes the generic class mapper gets wrong: a
     * `#[DataCollectionOf(X)]` collection is an array of X, and a `DateTimeInterface` is a formatted
     * string rather than a bare object.
     *
     * @return array<string, mixed>
     */
    private function propertySchema(string $fqcn, string $property, DType $clean, SchemaContext $context): array
    {
        $wrapped = $this->nestedWrap->diagnose($fqcn, $property, $clean);
        if ($wrapped !== null) {
            $context->diagnostic($wrapped);
        }

        $item = $this->reflector->dataCollectionOf($fqcn, $property);
        if ($item !== null) {
            return ['type' => 'array', 'items' => $context->convert(new ClassT($item))];
        }

        if (! self::hasDateTime($clean)) {
            return $context->convert($clean);
        }

        // Only the `U` cast format changes the wire TYPE (integer timestamp); every other explicit
        // format still renders as a string, shaped by the one date policy.
        $serialized = $this->reflector->dateTimeCastFormat($fqcn, $property) === DateWireFormat::UNIX
            ? ['type' => 'integer', 'description' => DateWireFormat::TIMESTAMP_NOTE]
            : DateWireFormat::serializedSchema($this->dateFormat);

        return $this->withDateTime($clean, $serialized, $context);
    }

    /**
     * The property's schema with its `DateTimeInterface` members replaced by the shape they serialise
     * to — CONTRIBUTED to the union rather than put in its place, so a `?CarbonImmutable` keeps the
     * `null` the API really sends and a `CarbonImmutable|int` keeps its integer arm. Date-time members
     * collapse to one, since they all serialise identically.
     *
     * @param  array<string, mixed>  $serialized
     * @return array<string, mixed>
     */
    private function withDateTime(DType $clean, array $serialized, SchemaContext $context): array
    {
        if (! $clean instanceof UnionT) {
            return $serialized;
        }

        $members = [];
        $dated = false;
        foreach ($clean->members as $member) {
            if ($member instanceof NullT) {
                continue;
            }

            if ($member instanceof ClassT && DataClassReflector::isDateTime($member->fqcn)) {
                if (! $dated) {
                    $members[] = $serialized;
                    $dated = true;
                }

                continue;
            }

            $members[] = $context->convertMember($member);
        }

        return SchemaUnion::of($members, $clean->containsNull(), $context->representation()->nullable);
    }

    /**
     * Whether the marker-stripped type is, or unions in, a `DateTimeInterface`. Public because the
     * request side asks the same question of the same type, and two answers would be two documents.
     */
    public static function hasDateTime(DType $clean): bool
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

    private function collection(ClassT $type, SchemaContext $context): SchemaResult
    {
        // Spatie's collection generics are `<TKey of array-key, TValue>`, so the item is the LAST arg.
        // Reading typeArgs[0] on `PaginatedDataCollection<int, AuthorData>` documents `{type: integer}`.
        $item = DataClassReflector::collectionValueType($type);
        $items = $item !== null ? $context->convert($item) : [];

        // Always wrapped: the items key IS the wrap key, and the envelope never nests under a second one.
        $kind = $this->reflector->collectionKind($type->fqcn);
        if ($kind === 'length' || $kind === 'cursor') {
            $dataKey = $this->wrap->key(null) ?? 'data';

            // The envelope's links/meta are one component per shape; only `data` is per Data class.
            $schema = PaginationParts::hoist(
                $context,
                SpatieDataEnvelope::of($kind, $items, $dataKey),
                SpatieDataEnvelope::parts($kind),
            );

            // One page-of-X component per Data class and kind, so N paginated operations share it.
            $reference = PageComponent::reference(
                $context,
                $kind,
                $item instanceof ClassT ? $item->fqcn : null,
                $items,
                $schema,
            );

            return new SchemaResult($reference ?? $schema, 0.9);
        }

        // A plain DataCollection is published as a bare array. At runtime spatie wraps a nested one
        // under the global `data.wrap`; that divergence is reported by {@see NestedCollectionWrap}
        // rather than modelled, for the reason on the class docblock.
        $schema = ['type' => 'array', 'items' => $items];
        $key = $context->atRoot() ? $this->wrap->key(null) : null;
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
