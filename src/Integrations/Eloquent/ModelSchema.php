<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\ComponentHoist;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Schema\MockHints;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Maps an Eloquent model to an object schema, superseding the core class mapper for models. Named by
 * `#[SchemaName]` (else the short class name), pinned by `#[SchemaId]` (else the FQCN). Design:
 * docs/design/inference-embedding.md §"Eloquent column source".
 *
 * Columns come from a union of sources, most authoritative first: the engine's {@see ClassMetadata}
 * (a real model declares no PHP column properties, so this is nearly all `@property`/`@property-read`
 * docblock tags), then floor sources reflected off the model — a `$casts` key IS a column, a `$dates`
 * entry is a date-time, and a `$fillable`-only name is permissive at lowered confidence.
 *
 * {@see EloquentModelReflector} facts then refine the set. Points worth knowing:
 *
 * - `$casts` pin a column's shape ({@see CastSchema}); an enum cast goes through the Enum path, an
 *   `AsEnumCollection:Enum` is an array of that enum's values, and a custom `CastsAttributes` caster
 *   is typed by its `get()` return type.
 * - accessors ({@see AccessorReader}) OVERRIDE the column/cast they shadow, mirroring the
 *   mutated-then-cast precedence in `HasAttributes::attributesToArray`.
 * - `$with` relations serialise on every response, so each becomes a nested model schema under the
 *   snake-cased key (to-many → array, to-one → nullable ref), depth-capped by the shared
 *   component-hoist cycle break — a relation back to a model mid-expansion becomes a `$ref`.
 * - a `serializeDate()` override makes the wire format statically unknowable, so date casts weaken to
 *   a plain string (no `format`) plus a diagnostic.
 *
 * A model no source yields columns for renders as a bare object plus an info diagnostic telling the
 * author to add `@property` tags — never silently.
 *
 * @phpstan-type ModelFacts array{hidden: list<string>, visible: list<string>, appends: list<string>, casts: array<string, string>, classHidden: list<string>, fillable: list<string>, dates: list<string>, with: list<string>, timestamps: bool, softDeletes: bool, overridesSerializeDate: bool, keyName: string, keySchema: array<string, mixed>}
 */
#[ExtensionOrder(priority: Priorities::EARLY)]
final class ModelSchema implements TypeToSchema
{
    public function __construct(
        private readonly EloquentModelReflector $reflector = new EloquentModelReflector,
        private readonly AccessorReader $accessors = new AccessorReader,
        private readonly ComponentHoist $hoist = new ComponentHoist,
    ) {}

    public function supports(DType $type): bool
    {
        return $type instanceof ClassT && EloquentModelReflector::isModel($type->fqcn);
    }

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        $fqcn = $type->fqcn;

        return $this->hoist->hoist($context, $fqcn, function () use ($fqcn, $context): array {
            $facts = $this->reflector->facts($fqcn);
            $metadata = $context->engine()->classMetadata(new ClassRef($fqcn));

            // Editing the model (new column/cast, changed $hidden) must invalidate the warm fragment.
            // Enum-cast files are recorded later, as each cast resolves in castSchema().
            $context->dependsOn(...$metadata->dependencyFiles);

            $hidden = [...$facts['hidden'], ...$facts['classHidden']];

            $properties = [];
            $required = [];
            foreach ($metadata->properties as $property) {
                if (! self::isColumnVisible($property->name, $facts['visible'], $hidden)) {
                    continue;
                }

                $schema = $this->columnSchema($property->name, $property->type, $facts, $context);
                if ($property->summary !== null) {
                    $schema['description'] = $property->summary;
                }
                $properties[$property->name] = $schema;

                // A declared column's key is always present, so required even when nullable —
                // nullability lives in the value's type union, not in presence.
                $required[] = $property->name;
            }

            // Columns the engine didn't surface but the model evidences. Docblock/native columns above
            // are more authoritative, so an already-present name is left alone.
            foreach ($this->floorColumns($facts) as $column) {
                if (isset($properties[$column]) || ! self::isColumnVisible($column, $facts['visible'], $hidden)) {
                    continue;
                }

                [$schema, $isRequired] = $this->floorColumnSchema($column, $facts, $context);
                $properties[$column] = $schema;
                if ($isRequired) {
                    $required[] = $column;
                }
            }

            // created_at/updated_at/deleted_at — always present on a persisted model, so required.
            foreach ($this->frameworkColumns($facts) as [$name, $schema]) {
                if (isset($properties[$name]) || ! self::isColumnVisible($name, $facts['visible'], $hidden)) {
                    continue;
                }
                $properties[$name] = $schema;
                $required[] = $name;
            }

            // HasUuids/HasUlids definitively fix the key's format, beating a stale docblock type.
            $key = $facts['keyName'];
            if (isset($properties[$key], $facts['keySchema']['format'])) {
                $properties[$key] = $facts['keySchema'];
            }

            if ($properties === []) {
                $context->diagnostic(new Diagnostic(
                    severity: Severity::Info,
                    code: 'eloquent.no-columns',
                    message: sprintf('Model %s exposes no documentable columns; its response is documented as a bare object.', $fqcn),
                    help: 'Add `@property` (or `@property-read`) docblock tags for the model\'s attributes — e.g. `@property int $id` — so its columns and their types are recovered.',
                ));
            }

            // Appends stay permissive unless a cast pins the shape or the accessor pass below types it.
            foreach ($facts['appends'] as $append) {
                if (isset($properties[$append])) {
                    continue;
                }
                $properties[$append] = $this->castSchema($append, $facts, $context) ?? [];
            }

            $this->applyAccessors($fqcn, $facts, $properties, $required, $context);
            $this->applyEagerLoads($fqcn, $facts, $properties, $required, $context);

            if ($facts['overridesSerializeDate']) {
                $context->diagnostic(new Diagnostic(
                    severity: Severity::Info,
                    code: 'eloquent.custom-date-serialization',
                    message: sprintf('Model %s overrides serializeDate(), so its date attributes\' wire format is not statically known; they are documented as plain strings.', $fqcn),
                    help: 'The date/datetime columns are documented as `type: string` without a `format`, and no annotation puts one back: no attribute carries a column format, and a docblock type has no format to state. If clients need an exact one, state it in an overlay, which corrects the document and leaves this notice naming the model.',
                ));
            }

            $object = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $object['required'] = $required;
            }

            // A column is a magic property, so only the class-level #[Mock] form can name one.
            return MockHints::applyTo($context, $object, $fqcn);
        });
    }

    /**
     * Types every accessor Laravel actually serialises — one shadowing a column, or an append — from
     * its engine-recovered return type. An accessor that is neither isn't serialised, so it's skipped;
     * an unrecoverable return type leaves the existing schema as it stands.
     *
     * @param  ModelFacts  $facts
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     */
    private function applyAccessors(string $fqcn, array $facts, array &$properties, array &$required, SchemaContext $context): void
    {
        foreach ($this->accessors->read($fqcn) as $accessor) {
            $attribute = $accessor['attribute'];
            if (! isset($properties[$attribute])) {
                continue;
            }

            $analysis = $context->engine()->analyzeCallable($accessor['ref']);
            $context->dependsOn(...$analysis->dependencyFiles);

            $type = self::returnType($analysis);
            if ($type === null) {
                continue;
            }

            $schema = $context->convert($type);
            if ($schema === []) {
                continue;
            }

            $properties[$attribute] = $schema;
        }
    }

    /**
     * Adds each `$with` relation as a nested model schema under its snake-cased key. The related model
     * comes from the relation method's return type (`HasMany<Comment>` → `Comment`); an unresolvable
     * one gets an info diagnostic rather than a guess.
     *
     * @param  ModelFacts  $facts
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     */
    private function applyEagerLoads(string $fqcn, array $facts, array &$properties, array &$required, SchemaContext $context): void
    {
        if ($facts['with'] === []) {
            return;
        }

        $file = self::file($fqcn);
        if ($file === null) {
            return;
        }

        foreach ($facts['with'] as $relation) {
            $key = Str::snake($relation);
            if (isset($properties[$key])) {
                continue;
            }

            $analysis = $context->engine()->analyzeCallable(new CallableRef($file, $fqcn, $relation));
            $context->dependsOn(...$analysis->dependencyFiles);

            $resolved = self::resolveRelation($analysis);
            if ($resolved === null) {
                $context->diagnostic(new Diagnostic(
                    severity: Severity::Info,
                    code: 'eloquent.unresolved-eager-load',
                    message: sprintf('Could not resolve the related model of %s::%s() (declared in $with), so it is omitted from the schema.', $fqcn, $relation),
                    help: 'Give the relation method a generic return type — e.g. `HasMany<Comment, $this>` — so its related model can be recovered.',
                ));

                continue;
            }

            [$relatedFqcn, $toMany] = $resolved;
            $itemSchema = $context->convert(new ClassT($relatedFqcn));
            if ($itemSchema === []) {
                continue;
            }

            $properties[$key] = $toMany
                ? ['type' => 'array', 'items' => $itemSchema]
                : ['anyOf' => [$itemSchema, ['type' => 'null']]];
            $required[] = $key;
        }
    }

    /**
     * `[related FQCN, isToMany]` off a relation method's analysed return type
     * (`HasMany<Comment, $this>` → `[Comment, true]`), or null when no return path carries a model.
     *
     * @return array{0: string, 1: bool}|null
     */
    private static function resolveRelation(ActionAnalysis $analysis): ?array
    {
        foreach ($analysis->returns as $return) {
            $type = $return->type;
            if (! $type instanceof ClassT || ! is_a($type->fqcn, 'Illuminate\\Database\\Eloquent\\Relations\\Relation', true)) {
                continue;
            }

            $related = $type->typeArgs[0] ?? null;
            if ($related instanceof ClassT && EloquentModelReflector::isModel($related->fqcn)) {
                return [$related->fqcn, self::isToMany($type->fqcn)];
            }
        }

        return null;
    }

    /** A to-many relation serialises as an array. */
    private static function isToMany(string $relation): bool
    {
        return is_a($relation, BelongsToMany::class, true)
            || is_a($relation, HasManyThrough::class, true)
            // HasOneOrMany covers HasMany/MorphMany (to-many) and HasOne/MorphOne (to-one).
            || (is_a($relation, HasOneOrMany::class, true) && ! is_a($relation, 'Illuminate\\Database\\Eloquent\\Relations\\HasOne', true) && ! is_a($relation, 'Illuminate\\Database\\Eloquent\\Relations\\MorphOne', true));
    }

    /**
     * The union of the analysis's return-path types, void/never dropped. Null when nothing concrete
     * survives, so callers leave the existing schema alone rather than degrade it.
     */
    private static function returnType(ActionAnalysis $analysis): ?DType
    {
        $types = [];
        foreach ($analysis->returns as $return) {
            if ($return->type instanceof VoidT || $return->type instanceof NeverT) {
                continue;
            }
            $types[] = $return->type;
        }

        if ($types === []) {
            return null;
        }

        $union = UnionT::of($types);

        return $union instanceof UnknownT ? null : $union;
    }

    /**
     * The schema for a column: its cast shape when the model casts it, else its inferred type.
     *
     * @param  ModelFacts  $facts
     * @return array<string, mixed>
     */
    private function columnSchema(string $column, DType $type, array $facts, SchemaContext $context): array
    {
        $cast = $this->castSchema($column, $facts, $context);
        if ($cast === null) {
            return $context->convert($type);
        }

        // A cast only describes the non-null shape, so widen when the column type admits null.
        return $type instanceof UnionT && $type->containsNull() ? self::widenNullable($cast) : $cast;
    }

    /**
     * Adds a `null` branch to a fragment's `type` (2020-12 `[t, null]` form). Enum and already-nullable
     * fragments pass through untouched.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function widenNullable(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_string($type) && $type !== 'null') {
            $schema['type'] = [$type, 'null'];
        } elseif (is_array($type) && ! in_array('null', $type, true)) {
            $schema['type'] = [...array_values($type), 'null'];
        }

        return $schema;
    }

    /**
     * The timestamp / soft-delete columns as `[name, schema]` pairs. created_at/updated_at are non-null
     * on any persisted model; deleted_at is null unless the row is trashed.
     *
     * @param  ModelFacts  $facts
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function frameworkColumns(array $facts): array
    {
        $weaken = $facts['overridesSerializeDate'];
        $dateTime = $weaken ? ['type' => 'string'] : ['type' => 'string', 'format' => 'date-time'];
        $nullableDateTime = $weaken ? ['type' => ['string', 'null']] : ['type' => ['string', 'null'], 'format' => 'date-time'];

        $columns = [];
        if ($facts['timestamps']) {
            $columns[] = ['created_at', $dateTime];
            $columns[] = ['updated_at', $dateTime];
        }
        if ($facts['softDeletes']) {
            $columns[] = ['deleted_at', $nullableDateTime];
        }

        return $columns;
    }

    /**
     * Floor column names in priority order — `$casts` keys, `$dates`, `$fillable` — deduped with first
     * occurrence winning, so the most authoritative source decides the type in {@see floorColumnSchema()}.
     *
     * @param  ModelFacts  $facts
     * @return list<string>
     */
    private function floorColumns(array $facts): array
    {
        $seen = [];
        foreach ([...array_keys($facts['casts']), ...$facts['dates'], ...$facts['fillable']] as $name) {
            $seen[$name] = true;
        }

        return array_keys($seen);
    }

    /**
     * `[schema, isRequired]` for a floor column: the cast shape when cast, a date-time for a `$dates`
     * entry, else permissive `{}` at lowered confidence. Cast/date columns are always serialised so
     * they're required; a `$fillable`-only one stays optional because its presence is a guess.
     *
     * @param  ModelFacts  $facts
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function floorColumnSchema(string $column, array $facts, SchemaContext $context): array
    {
        $cast = $this->castSchema($column, $facts, $context);
        if ($cast !== null) {
            return [$cast, true];
        }

        if (in_array($column, $facts['dates'], true)) {
            return [$facts['overridesSerializeDate'] ? ['type' => 'string'] : ['type' => 'string', 'format' => 'date-time'], true];
        }

        $context->lowerConfidence(0.6);

        return [[], false];
    }

    /**
     * The shape a cast pins for a column, or null when there's no recognised cast (the column then falls
     * back to its inferred type). Resolution order mirrors `HasAttributes::castAttribute`.
     *
     * @param  ModelFacts  $facts
     * @return array<string, mixed>|null
     */
    private function castSchema(string $column, array $facts, SchemaContext $context): ?array
    {
        $cast = $facts['casts'][$column] ?? null;
        if ($cast === null) {
            return null;
        }

        if ($facts['overridesSerializeDate'] && CastSchema::isDateCast($cast)) {
            return ['type' => 'string'];
        }

        if (CastSchema::isEnum($cast)) {
            return $this->enumSchema(explode(':', $cast, 2)[0], $context);
        }

        $enumCollection = CastSchema::enumCollectionEnum($cast);
        if ($enumCollection !== null && enum_exists($enumCollection)) {
            return ['type' => 'array', 'items' => $this->enumSchema($enumCollection, $context)];
        }

        return $this->customCasterSchema($cast, $context) ?? CastSchema::forCast($cast);
    }

    /**
     * A backed enum's schema via the Enum path (backing values, `x-enumDescriptions`), recording the
     * enum's file as a fragment-cache dependency.
     *
     * @return array<string, mixed>
     */
    private function enumSchema(string $enum, SchemaContext $context): array
    {
        $enumFile = EnumReflection::file($enum);
        if ($enumFile !== null) {
            $context->dependsOn($enumFile);
        }

        return $context->convert(new EnumT($enum, EnumReflection::names($enum)));
    }

    /**
     * A custom `CastsAttributes` caster's shape, taken from its engine-recovered `get()` return type.
     * Null when the cast isn't such a caster or the return type can't be recovered.
     *
     * @return array<string, mixed>|null
     */
    private function customCasterSchema(string $cast, SchemaContext $context): ?array
    {
        $base = explode(':', $cast, 2)[0];
        if (! class_exists($base) || ! is_a($base, CastsAttributes::class, true)) {
            return null;
        }

        $file = self::file($base);
        if ($file === null) {
            return null;
        }

        $context->dependsOn($file);
        $analysis = $context->engine()->analyzeCallable(new CallableRef($file, $base, 'get'));
        $context->dependsOn(...$analysis->dependencyFiles);

        $type = self::returnType($analysis);
        if ($type === null) {
            return null;
        }

        $schema = $context->convert($type);

        return $schema === [] ? null : $schema;
    }

    /** The file a class is declared in, or null when it isn't reflectable. */
    private static function file(string $fqcn): ?string
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        try {
            $file = (new ReflectionClass($fqcn))->getFileName();

            return $file === false ? null : $file;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $visible
     * @param  list<string>  $hidden
     */
    private static function isColumnVisible(string $column, array $visible, array $hidden): bool
    {
        // $visible is an allow-list when set; otherwise everything not in $hidden is visible.
        return $visible !== [] ? in_array($column, $visible, true) : ! in_array($column, $hidden, true);
    }
}
