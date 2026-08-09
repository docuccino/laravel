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
 * Maps an Eloquent model to an object schema (superseding the core class mapper for models).
 *
 * The column universe is a union, most-authoritative first (design — see
 * docs/design/inference-embedding.md §"Eloquent column source"):
 *
 * 1. the engine's {@see ClassMetadata} — a real model declares no PHP column
 *    properties, so this is almost entirely its class-level `@property`/`@property-read` docblock tags
 *    (typed, high confidence); a native public property, where one exists, also lands here;
 * 2. floor sources reflected off the model — a `$casts` key IS a column (typed via its cast), a
 *    `$dates` entry is a date-time column, and a `$fillable`-only name is a permissive column at
 *    lowered confidence.
 *
 * The model's own presentation facts ({@see EloquentModelReflector}) then refine the set:
 *
 * - `$visible` (allow-list) / `$hidden` + a class-level `#[Hidden]` list (deny-list) filter columns.
 * - `$casts` fix the schema of a column: datetime → `format: date-time`, native casts fix the type
 *   ({@see CastSchema}); an enum cast routes the column through the Enum integration path (`EnumT`);
 *   an `AsEnumCollection:Enum` cast is an array of that enum's values; a custom `CastsAttributes`
 *   caster is typed by its `get()` return type (recovered by the engine).
 * - `$appends` add accessor-backed properties (optional).
 * - Accessors ({@see AccessorReader}) — classic `getXxxAttribute()` and `Attribute::make(get: …)` —
 *   type an appended attribute and OVERRIDE the column/cast they shadow (mirroring the mutated-then-
 *   cast precedence in `HasAttributes::attributesToArray`), their return type recovered by the engine.
 * - `$with` relations serialise on every response, so each becomes a nested model schema under the
 *   snake-cased relation key (to-many → an array; to-one → a nullable reference), depth-capped by the
 *   shared component-hoist cycle break (a relation back to a model already mid-expansion is a `$ref`).
 * - a `serializeDate()` override makes every date attribute's wire format statically unknowable, so
 *   date/datetime cast claims are weakened to a plain string (the `format` is dropped) + a diagnostic.
 *
 * When NO source yields a column, today's behaviour is kept (an empty object plus any appends) but an
 * info diagnostic tells the author how to document columns (`@property` docblocks) — never silent.
 *
 * The component is named by `#[SchemaName]` (else the short class name) and pinned by `#[SchemaId]`
 * (else the FQCN); self-references are cycle-broken via the reserved name.
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

            // The model's reflected shape is a fragment-cache dependency (design §10): editing the model
            // (a new column/cast, a changed $hidden list) must invalidate the warm fragment. Enum-cast
            // third files are recorded as each cast is resolved in castSchema().
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

                // A declared column is always serialised (its key is present), so it is required — even
                // when nullable. Nullability is a property of the VALUE, carried in the schema's type
                // union, not of presence.
                $required[] = $property->name;
            }

            // Floor columns: a column the engine did not surface but the model itself evidences —
            // a `$casts` key (typed by its cast), a `$dates` entry (date-time), or a `$fillable`-only
            // name (permissive, at lowered confidence). Docblock/native columns above are more
            // authoritative, so an already-present name is left untouched.
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

            // Framework-synthesised columns Laravel injects at serialization time: created_at/updated_at
            // (when the model uses timestamps) and deleted_at (when it soft-deletes). They are always
            // present on a persisted model, so required — deleted_at is null unless the row is trashed.
            foreach ($this->frameworkColumns($facts) as [$name, $schema]) {
                if (isset($properties[$name]) || ! self::isColumnVisible($name, $facts['visible'], $hidden)) {
                    continue;
                }
                $properties[$name] = $schema;
                $required[] = $name;
            }

            // Primary-key format: HasUuids/HasUlids definitively make the key a string with a uuid/ulid
            // format, overriding a stale inferred/docblock type on the key column.
            $key = $facts['keyName'];
            if (isset($properties[$key], $facts['keySchema']['format'])) {
                $properties[$key] = $facts['keySchema'];
            }

            // No source yielded a column: keep the empty-object behaviour but tell the author how to
            // document one, so an undocumented model never renders as a silent bare object.
            if ($properties === []) {
                $context->diagnostic(new Diagnostic(
                    severity: Severity::Info,
                    code: 'eloquent.no-columns',
                    message: sprintf('Model %s exposes no documentable columns; its response is documented as a bare object.', $fqcn),
                    help: 'Add `@property` (or `@property-read`) docblock tags for the model\'s attributes — e.g. `@property int $id` — so its columns and their types are recovered.',
                ));
            }

            // Appended accessors: optional, permissive unless a cast pins the shape or an accessor
            // (applied next) recovers a concrete type.
            foreach ($facts['appends'] as $append) {
                if (isset($properties[$append])) {
                    continue;
                }
                $properties[$append] = $this->castSchema($append, $facts, $context) ?? [];
            }

            // Accessors override the columns/appends they shadow with their recovered return type.
            $this->applyAccessors($fqcn, $facts, $properties, $required, $context);

            // $with relations serialise on every response as nested model schemas.
            $this->applyEagerLoads($fqcn, $facts, $properties, $required, $context);

            if ($facts['overridesSerializeDate']) {
                $context->diagnostic(new Diagnostic(
                    severity: Severity::Info,
                    code: 'eloquent.custom-date-serialization',
                    message: sprintf('Model %s overrides serializeDate(), so its date attributes\' wire format is not statically known; they are documented as plain strings.', $fqcn),
                    help: 'The date/datetime columns are documented as `type: string` without a `format`. Pin an exact format with an annotation if clients need one.',
                ));
            }

            $object = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $object['required'] = $required;
            }

            return $object;
        });
    }

    /**
     * Type each accessor Laravel serialises — one shadowing a real column (override) or an appended
     * attribute — with the engine-recovered return type of its classic getter / `Attribute` get
     * closure. An accessor that is neither a column nor an append is not serialised, so it is skipped;
     * an accessor whose return type the engine cannot recover leaves the existing schema untouched
     * (the column keeps its cast; the append stays permissive).
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
     * Add each `$with` relation as a nested model schema under its snake-cased key: a to-many relation
     * is an array of the related model, a to-one relation a nullable reference to it. The related model
     * is resolved from the relation method's return type (`HasMany<Comment>` → `Comment`), recovered by
     * the engine; an unresolvable relation is flagged with an info diagnostic rather than guessed at.
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
     * The related model FQCN + whether the relation is to-many, read from a relation method's analysed
     * return type (`Illuminate\...\Relations\HasMany<Comment, $this>` → `[Comment, true]`), or null when
     * no return path yields a relation carrying a model type argument.
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

    /** Whether a relation FQCN is a to-many relation (its serialised value is an array). */
    private static function isToMany(string $relation): bool
    {
        return is_a($relation, BelongsToMany::class, true)
            || is_a($relation, HasManyThrough::class, true)
            // HasOneOrMany covers HasMany/MorphMany (to-many) and HasOne/MorphOne (to-one).
            || (is_a($relation, HasOneOrMany::class, true) && ! is_a($relation, 'Illuminate\\Database\\Eloquent\\Relations\\HasOne', true) && ! is_a($relation, 'Illuminate\\Database\\Eloquent\\Relations\\MorphOne', true));
    }

    /**
     * The union of an analysis's return-path types, dropping void/never/unknown paths; null when no
     * concrete type survives (so the caller leaves the existing schema untouched rather than degrading).
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

        // The cast fixes the non-null serialised shape; when the column type admits null, widen it so
        // the schema is string-or-null (not a non-nullable string on an always-present nullable column).
        return $type instanceof UnionT && $type->containsNull() ? self::widenNullable($cast) : $cast;
    }

    /**
     * Add a `null` branch to a cast fragment's `type` (2020-12 `[t, null]` form), leaving an enum or
     * already-nullable fragment untouched.
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
     * The framework-synthesised timestamp / soft-delete columns, as `[name, schema]` pairs.
     * created_at/updated_at are non-null date-times (set on any persisted model); deleted_at is a
     * nullable date-time (null unless the row is soft-deleted). A serializeDate() override weakens the
     * date-time format to a plain string (the wire format is no longer statically known).
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
     * The floor-source column names, in deterministic priority order: `$casts` keys, then `$dates`,
     * then `$fillable`. Deduped, first occurrence wins (so a name's most-authoritative floor source
     * decides its type in {@see floorColumnSchema()}).
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
     * The schema (and whether it is required) for a floor column: its cast shape when cast, a
     * date-time when a `$dates` entry (a plain string when serializeDate() is overridden), else a
     * permissive `{}` at lowered confidence for a `$fillable`-only name whose type is genuinely
     * unknown. Cast/date floor columns are treated as always-serialised (required); an untyped
     * permissive one is left optional, since its presence is a guess.
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
     * The schema a cast pins for a column, or null when the column has no cast this mapper recognises
     * (so it falls back to its inferred type). Resolution order mirrors `HasAttributes::castAttribute`:
     * a date cast weakened to a plain string when serializeDate() is overridden, then a backed-enum
     * cast (via the Enum path), an `AsEnumCollection`/`AsEnumArrayObject` (array of enum values), a
     * custom `CastsAttributes` caster (typed by its engine-recovered `get()` return type), and finally
     * the native cast table.
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
     * The enum schema for a backed-enum FQCN, routed through the Enum integration path (backing values,
     * `x-enumDescriptions`), recording the enum file as a fragment-cache dependency (design §10).
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
     * The schema for a column cast by a custom `CastsAttributes` caster — its engine-recovered `get()`
     * return type — or null when the cast is not such a caster or the return type is unrecoverable (so
     * the column falls back to its inferred type).
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

    /** The file a class is declared in, or null when it is not reflectable. */
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
