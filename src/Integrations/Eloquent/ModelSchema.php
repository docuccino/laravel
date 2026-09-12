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
use Docuccino\Core\Extensions\Schema\DocumentedExamples;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Extensions\Schema\MockHints;
use Docuccino\Core\Extensions\Schema\PropertyAnnotations;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Extensions\Schema\SchemaUnion;
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
 * - `$visible`/`$hidden`/`#[Hidden]` gate EVERY key published — columns, appends and eager-loaded
 *   relations alike — through the one reading in {@see serialises()}, because Laravel filters all
 *   three through the same `getArrayableItems()`.
 * - `$casts` pin a column's shape ({@see CastSchema}); an enum cast goes through the Enum path, an
 *   `AsEnumCollection:Enum` is an array of that enum's values, and a custom `CastsAttributes` caster
 *   is typed by its `get()` return type.
 * - accessors ({@see AccessorReader}) OVERRIDE the column/cast they shadow, mirroring the
 *   mutated-then-cast precedence in `HasAttributes::attributesToArray`.
 * - `$with` relations serialise on every response, so each becomes a nested model schema under the
 *   snake-cased key (to-many → array, to-one → nullable ref), depth-capped by the shared
 *   component-hoist cycle break — a relation back to a model mid-expansion becomes a `$ref`.
 * - a date attribute publishes whatever {@see DateColumnSchema} says, weakened `format` and
 *   diagnostic flag together.
 *
 * Both of this mapper's notices claim something about the DOCUMENT, so both are decided by what was
 * published rather than by what the model owns: the bare-object notice is raised after appends and
 * eager loads have had their chance to add a key, and the date-serialisation one by the keys that still
 * carry a weakened date once the accessors have retyped what they shadow
 * (docs/design/defect-classes.md §"A diagnostic that asserts an outcome it never reads").
 *
 * @phpstan-import-type ModelFacts from EloquentModelReflector
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

            $properties = [];
            $required = [];
            // The keys that gave a `format` up, per key rather than as a flag because the accessor pass
            // below can retype one. A local rather than state: ComponentHoist re-enters toSchema() per
            // eager-loaded relation, and a nested model's weakened date is not this model's.
            /** @var list<string> $weakenedDates */
            $weakenedDates = [];
            // Reflection reports the framework's own public properties ($exists, $timestamps, …) beside
            // the docblock columns, and every model inherits all of them; none is an attribute, so none
            // is ever in a response ({@see EloquentModelReflector::frameworkProperties()}).
            $bookkeeping = EloquentModelReflector::frameworkProperties();
            foreach ($metadata->properties as $property) {
                if (in_array($property->name, $bookkeeping, true) || ! self::serialises($property->name, $facts)) {
                    continue;
                }

                $schema = $this->columnSchema($property->name, $property->type, $facts, $context, $weakenedDates);
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
                if (isset($properties[$column]) || ! self::serialises($column, $facts)) {
                    continue;
                }

                [$schema, $isRequired] = $this->floorColumnSchema($column, $facts, $context, $weakenedDates);
                $properties[$column] = $schema;
                if ($isRequired) {
                    $required[] = $column;
                }
            }

            // created_at/updated_at/deleted_at — always present on a persisted model, so required. The
            // shape is asked for HERE rather than in the helper, so a column the visibility gate drops
            // never reaches the date policy and never counts towards its notice.
            foreach (self::frameworkColumns($facts) as [$name, $nullable]) {
                if (isset($properties[$name]) || ! self::serialises($name, $facts)) {
                    continue;
                }
                $schema = self::datedSchema($name, $facts, $weakenedDates);
                $properties[$name] = $nullable
                    ? SchemaUnion::nullable($schema, $context->representation()->nullable)
                    : $schema;
                $required[] = $name;
            }

            // HasUuids/HasUlids definitively fix the key's format, beating a stale docblock type.
            $key = $facts['keyName'];
            if (isset($properties[$key], $facts['keySchema']['format'])) {
                $properties[$key] = $facts['keySchema'];
            }

            // Appends stay permissive unless a cast pins the shape or the accessor pass below types it.
            foreach ($facts['appends'] as $append) {
                if (isset($properties[$append]) || ! self::serialises($append, $facts)) {
                    continue;
                }
                $properties[$append] = $this->castSchema($append, $facts, $context) ?? [];
            }

            // An accessor is serialised in place of the column it shadows and never through
            // `serializeDate()`, so a key it retypes is no longer a date the override weakened.
            // Sorted, because the sentence publishes these names: a report is a function of which
            // attributes lost a format, never of the order the property walk met them in.
            sort($weakenedDates);
            $weakenedDates = array_values(array_diff(
                $weakenedDates,
                $this->applyAccessors($fqcn, $facts, $properties, $required, $context),
            ));
            $this->applyEagerLoads($fqcn, $facts, $properties, $required, $context);

            // Asserted against the FINISHED set — see the header: appends and eager loads put keys in a
            // model no column source spoke for.
            if ($properties === []) {
                $context->diagnostic(new Diagnostic(
                    severity: Severity::Info,
                    code: 'eloquent.no-columns',
                    message: sprintf('Model %s exposes no documentable columns; its response is documented as a bare object.', $fqcn),
                    help: 'Add `@property` (or `@property-read`) docblock tags for the model\'s attributes — e.g. `@property int $id` — so its columns and their types are recovered.',
                ));
            }

            // The override alone is not the condition: it usually sits on a base model every class
            // extends, so most subclasses inherit it and publish no weakened date for it to reach.
            if ($weakenedDates !== []) {
                $context->diagnostic(new Diagnostic(
                    severity: Severity::Info,
                    code: 'eloquent.custom-date-serialization',
                    // The attributes are NAMED, not summarised: a model can carry both kinds at once —
                    // a cast that states its own format is written with it and never reaches the hook —
                    // so "its date attributes" would claim the loss for columns that kept their format.
                    message: sprintf('Model %s overrides serializeDate(), so the wire format of the date attributes it serialises through that hook (%s) is not statically known and the shapes recovered for them are plain strings with no format.', $fqcn, implode(', ', $weakenedDates)),
                    help: 'Those columns are recovered as `type: string` without a `format`, and no annotation puts one back: no attribute carries a column format, and a docblock type has no format to state. If clients need an exact one, state it in an overlay, which corrects the document and leaves this notice naming the model. A column whose cast names its own format (`datetime:d/m/Y`) is not among them.',
                ));
            }

            $object = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $object['required'] = $required;
            }

            // A column is a magic property, so only the class-level #[Mock] form can name one; a real
            // property carrying prose still publishes it where its name is a column. The docblock
            // example (30) is written first so the attribute (40) beats it, as the description does.
            $object = DocumentedExamples::applyTo($context, $object, $fqcn, $metadata->properties);
            $object = PropertyAnnotations::applyTo($context, $object, $fqcn);

            return MockHints::applyTo($context, $object, $fqcn);
        });
    }

    /**
     * The shape a date attribute publishes, recording the key when the `format` was given up.
     *
     * @param  ModelFacts  $facts
     * @param  list<string>  $weakenedDates
     * @return array<string, mixed>
     */
    private static function datedSchema(string $column, array $facts, array &$weakenedDates): array
    {
        if (DateColumnSchema::formatGivenUp($facts)) {
            $weakenedDates[] = $column;
        }

        return DateColumnSchema::schema($facts);
    }

    /**
     * Types every accessor Laravel actually serialises — one shadowing a column, or an append — from
     * its engine-recovered return type. An accessor that is neither isn't serialised, so it's skipped;
     * an unrecoverable return type leaves the existing schema as it stands.
     *
     * @param  ModelFacts  $facts
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return list<string> the keys whose schema the accessors replaced
     */
    private function applyAccessors(string $fqcn, array $facts, array &$properties, array &$required, SchemaContext $context): array
    {
        $retyped = [];
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
            $retyped[] = $attribute;
        }

        return $retyped;
    }

    /**
     * Adds each `$with` relation as a nested model schema under its snake-cased key. The related model
     * comes from the relation method's return type (`HasMany<Comment>` → `Comment`); an unresolvable
     * one gets an info diagnostic rather than a guess.
     *
     * A relation is judged by the name it is LOADED under, not the key it serialises as:
     * `relationsToArray()` filters `$this->relations` through {@see serialises()}'s reading and only
     * then snake-cases the surviving keys, so `$hidden = ['latestPost']` hides the relation and
     * `['latest_post']` hides nothing.
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
            if (isset($properties[$key]) || ! self::serialises($relation, $facts)) {
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

            // A to-one relation is null when the row has no parent, expressed the same way every other
            // nullable member of the document is.
            $properties[$key] = $toMany
                ? ['type' => 'array', 'items' => $itemSchema]
                : SchemaUnion::nullable($itemSchema, $context->representation()->nullable);
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
     * The schema for a column: its cast shape when the model casts it, the date policy's when the model
     * treats it as a date attribute ({@see DateColumnSchema}), else its inferred type. The date branch
     * sits here rather than after the loops below, which skip a name this one already took.
     *
     * @param  ModelFacts  $facts
     * @param  list<string>  $weakenedDates
     * @return array<string, mixed>
     */
    private function columnSchema(string $column, DType $type, array $facts, SchemaContext $context, array &$weakenedDates): array
    {
        $pinned = $this->castSchema($column, $facts, $context);
        if ($pinned === null && DateColumnSchema::isAttribute($column, $facts)) {
            $pinned = self::datedSchema($column, $facts, $weakenedDates);
        }

        if ($pinned === null) {
            return $context->convert($type);
        }

        // A pinned shape only describes the non-null one, so contribute it to the union when the column
        // type admits null — including when it is a `$ref`, which cannot carry `type: [x, null]` and so
        // has to take an explicit branch rather than silently forbid the null.
        return $type instanceof UnionT && $type->containsNull()
            ? SchemaUnion::nullable($pinned, $context->representation()->nullable)
            : $pinned;
    }

    /**
     * The timestamp / soft-delete columns the model really has, as `[name, isNullable]` pairs.
     * created_at/updated_at are non-null on any persisted model; deleted_at is null unless the row is
     * trashed.
     *
     * @param  ModelFacts  $facts
     * @return list<array{0: string, 1: bool}>
     */
    private static function frameworkColumns(array $facts): array
    {
        $columns = [];
        if ($facts['timestamps']) {
            foreach (DateColumnSchema::TIMESTAMPS as $name) {
                $columns[] = [$name, false];
            }
        }
        if ($facts['softDeletes']) {
            $columns[] = [DateColumnSchema::DELETED_AT, true];
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
     * `[schema, isRequired]` for a floor column: the cast shape when cast, the date policy's shape when
     * the column is a date attribute, else permissive `{}` at lowered confidence. Cast/date columns are
     * always serialised so they're required; a `$fillable`-only one stays optional because its presence
     * is a guess.
     *
     * @param  ModelFacts  $facts
     * @param  list<string>  $weakenedDates
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function floorColumnSchema(string $column, array $facts, SchemaContext $context, array &$weakenedDates): array
    {
        $cast = $this->castSchema($column, $facts, $context);
        if ($cast !== null) {
            return [$cast, true];
        }

        if (DateColumnSchema::isAttribute($column, $facts)) {
            return [self::datedSchema($column, $facts, $weakenedDates), true];
        }

        $context->lowerConfidence(0.6);

        return [[], false];
    }

    /**
     * The shape a cast pins for a column, or null when there's no recognised cast and when the cast is
     * one the date policy answers for — the column then falls back to the date policy or to its inferred
     * type, in that order. Resolution order mirrors `HasAttributes::castAttribute`.
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

        if (CastSchema::isEnum($cast)) {
            return $this->enumSchema(explode(':', $cast, 2)[0], $context);
        }

        $enumCollection = CastSchema::enumCollectionEnum($cast);
        if ($enumCollection !== null && enum_exists($enumCollection)) {
            return ['type' => 'array', 'items' => $this->enumSchema($enumCollection, $context)];
        }

        return $this->customCasterSchema($cast, $context) ?? CastSchema::written($cast);
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
     * Whether the server would put this key in the response — the ONE visibility reading, which every
     * key the schema publishes goes through, whatever contributed it: a column, an append, an
     * eager-loaded relation. Laravel runs all three through `HasAttributes::getArrayableItems()`, which
     * intersects with `$visible` when that list is set and subtracts `$hidden` AFTER — so a name in both
     * lists is hidden, and the allow-list never puts back what the deny-list removed. A class-level
     * `#[Hidden]` name subtracts alongside `$hidden`.
     *
     * @param  ModelFacts  $facts
     */
    private static function serialises(string $key, array $facts): bool
    {
        if ($facts['visible'] !== [] && ! in_array($key, $facts['visible'], true)) {
            return false;
        }

        return ! in_array($key, $facts['hidden'], true) && ! in_array($key, $facts['classHidden'], true);
    }
}
