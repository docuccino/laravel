<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\SpatieData;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Schema\MockHints;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\UnionT;
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
 * Wrapping otherwise applies only at the response root ({@see SchemaContext::depth()} === 1), from
 * {@see WrapResolver} — so a nested Data property's shared `$ref` stays wrap-free.
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

    /** Wraps a root Data object under its wrap key; nested or unwrapped results pass through. */
    private function wrapRoot(SchemaResult $result, string $fqcn, SchemaContext $context): SchemaResult
    {
        if ($context->depth() !== 1) {
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
                $keys[$property->name] = $key;
                $properties[$key] = $schema;

                // Required even when nullable — spatie still emits the key carrying `null`, so
                // nullability lives in the value's type union. Only an Optional/Lazy marker changes this.
                if (! $this->reflector->isPropertyOptional($fqcn, $property->name)) {
                    $required[] = $key;
                }
            }

            $object = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $object['required'] = $required;
            }

            // #[MapName] can rename a property on the wire, so a hint follows the property to whatever
            // key it publishes under.
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
        $item = $this->reflector->dataCollectionOf($fqcn, $property);
        if ($item !== null) {
            return ['type' => 'array', 'items' => $context->convert(new ClassT($item))];
        }

        // Only the `U` cast format changes the wire TYPE (integer timestamp); every other explicit
        // format still renders as a date/date-time string below.
        if ($this->reflector->dateTimeCastFormat($fqcn, $property) === 'U') {
            return ['type' => 'integer', 'description' => 'Unix timestamp (seconds).'];
        }

        if ($this->isDateTime($clean)) {
            return ['type' => 'string', 'format' => $this->dateFormatToOas()];
        }

        return $context->convert($clean);
    }

    /** Whether the marker-stripped type is, or unions in, a `DateTimeInterface`. */
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

        // A plain DataCollection is a bare array, wrapped only at the response root.
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
