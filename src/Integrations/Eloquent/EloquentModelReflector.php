<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Core\Extensions\BuiltIn\JsonTypes;
use Docuccino\Core\Extensions\Schema\SchemaIdentity;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Reads the presentation facts a model declares — `$visible`/`$hidden`/`$appends`, `$casts`, a
 * class-level `#[Hidden]` — off the class's default property values. The model is never instantiated,
 * so no boot side effects and no DB access. These refine the columns {@see ClassMetadata} supplies.
 *
 * @phpstan-type ModelFacts array{hidden: list<string>, visible: list<string>, appends: list<string>, casts: array<string, string>, classHidden: list<string>, fillable: list<string>, dates: list<string>, with: list<string>, timestamps: bool, softDeletes: bool, overridesSerializeDate: bool, keyName: string, keySchema: array<string, mixed>}
 */
final class EloquentModelReflector
{
    public const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    /**
     * The chain an implicit binding's column comes out of. Overriding ANY of them takes the answer out
     * of the declarations {@see routeKeyNameFor()} can read.
     *
     * @var list<string>
     */
    private const ROUTE_KEY_METHODS = ['getRouteKeyName', 'getKeyName'];

    public function __construct(
        private readonly CastsMethodReader $castsMethod = new CastsMethodReader,
    ) {}

    private const SOFT_DELETES = 'Illuminate\\Database\\Eloquent\\SoftDeletes';

    private const HAS_UUIDS = 'Illuminate\\Database\\Eloquent\\Concerns\\HasUuids';

    private const HAS_ULIDS = 'Illuminate\\Database\\Eloquent\\Concerns\\HasUlids';

    private const SERIALIZE_DATE = 'serializeDate';

    /** @var list<string>|null the framework's own public properties, read once per process. */
    private static ?array $frameworkProperties = null;

    /** A concrete Eloquent model — the schema mapper's trigger. */
    public static function isModel(string $fqcn): bool
    {
        return $fqcn !== self::MODEL && is_a($fqcn, self::MODEL, true);
    }

    /**
     * The public instance properties EVERY model inherits from the framework — `$exists`, `$timestamps`,
     * `$incrementing` and the rest. None is ever serialised: `attributesToArray()` reads
     * `$this->attributes`, the appends and the relations, and a declared PHP property is in none of the
     * three. Reflection reports them alongside a model's `@property` column tags, so the schema mapper
     * has to know them by name to keep them out of the document.
     *
     * Read off the resolved Laravel's own base class rather than listed here, so the set is the
     * installed version's. Matched by NAME and not by declaring class, because `public $timestamps =
     * false` on the model itself is idiomatic and is still the framework's flag rather than a column.
     *
     * @return list<string>
     */
    public static function frameworkProperties(): array
    {
        if (self::$frameworkProperties !== null) {
            return self::$frameworkProperties;
        }

        $names = [];
        if (class_exists(self::MODEL)) {
            foreach ((new ReflectionClass(self::MODEL))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if (! $property->isStatic()) {
                    $names[] = $property->getName();
                }
            }
        }

        return self::$frameworkProperties = $names;
    }

    /**
     * The schema a `{model}` path parameter resolves to, without the full {@see facts()} pass. Shares
     * {@see keySchema()} with the model mapper so a bound path param and the model's own key column can't
     * disagree; anything unreflectable falls back to `integer`. A route naming its own column —
     * `{post:slug}` — goes through {@see columnSchemaFor()} instead. A model that overrides
     * `getRouteKeyName()` to bind on some other column is still out of scope: the override is a method
     * body, not a declaration, so the PK schema remains the closest static answer.
     *
     * @return array<string, mixed>
     */
    public function keySchemaFor(string $fqcn): array
    {
        if (! self::isModel($fqcn) || ! class_exists($fqcn)) {
            return ['type' => 'integer'];
        }

        $reflection = new ReflectionClass($fqcn);

        return self::keySchema($reflection->getDefaultProperties(), self::traits($fqcn));
    }

    /**
     * The column a `{model}` path parameter is matched on, or null when the model decides that
     * somewhere no reflection can read.
     *
     * Laravel resolves an implicit binding through `getRouteKeyName()`, which defaults to
     * `getKeyName()`, which answers the declared `$primaryKey`. Only that declaration is readable, so
     * a model overriding either method — a trait it imports counts, since PHP reports a flattened
     * method as the using class's — has chosen a column this cannot see, and answering `id` for one of
     * those would tell a consumer to send an id where the server matches a slug. Null is what
     * {@see keySchemaFor()} cannot do: that one owes a schema either way and a primary-key shape is
     * still the closest static answer, where a NAME is either right or a lie.
     */
    public function routeKeyNameFor(string $fqcn): ?string
    {
        if (! self::isModel($fqcn) || ! class_exists($fqcn)) {
            return null;
        }

        foreach (self::ROUTE_KEY_METHODS as $method) {
            if ((new ReflectionMethod($fqcn, $method))->getDeclaringClass()->getName() !== self::MODEL) {
                return null;
            }
        }

        return $this->facts($fqcn)['keyName'];
    }

    /**
     * The schema for the NAMED column a `{post:slug}` parameter binds on, or null when nothing types it.
     * Precedence mirrors {@see ModelSchema}'s, so no source is consulted here that a response body would
     * not: uuid/ulid key, then a `$casts` entry stating its own format, then the date policy, then the
     * engine's `@property` type. What the matching SOURCE says can differ, because the question does, so
     * this reads the cast table's request direction ({@see CastSchema::accepted()}).
     *
     * A column whose type can't be carried in a URL segment (an `array` cast, a `@property` naming a class)
     * is refused rather than emitted — the parameter is a path segment, not the serialised attribute.
     * The Query Builder `FilterColumnResolver` answers the same question for a filter down a ladder of
     * its own, and shares the key/cast bracket with this one; `ColumnLadderAgreement` pins where the two
     * agree and the date rows where they do not, so editing either against that table keeps them in step.
     *
     * Answers the shape and whether the DATE branch gave a `format` up, so the caller reports the loss
     * without re-deciding which branch was taken ({@see DateColumnSchema}).
     *
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    public function columnSchemaFor(string $fqcn, string $column, ClassMetadata $metadata): array
    {
        if (! self::isModel($fqcn) || ! class_exists($fqcn)) {
            return [null, false];
        }

        $facts = $this->facts($fqcn);
        $isKey = $column === $facts['keyName'];

        // HasUuids/HasUlids fix the key's format outright.
        if ($isKey && isset($facts['keySchema']['format'])) {
            return [$facts['keySchema'], false];
        }

        $cast = $facts['casts'][$column] ?? null;
        if ($cast !== null) {
            if ($facts['overridesSerializeDate'] && CastSchema::serializesThroughDateHook($cast)) {
                return [DateColumnSchema::schema($facts), DateColumnSchema::formatGivenUp($facts)];
            }

            $schema = self::asPathSegment(CastSchema::accepted($cast));
            if ($schema !== null) {
                return [$schema, false];
            }
        }

        // Read once here rather than at each source below: a source that forgot to ask is how a date
        // column reached the document typed by its tag ({@see DateColumnSchema}).
        if (DateColumnSchema::isAttribute($column, $facts)) {
            return [DateColumnSchema::schema($facts), DateColumnSchema::formatGivenUp($facts)];
        }

        foreach ($metadata->properties as $property) {
            if ($property->name === $column) {
                $schema = self::segmentSchema($property->type);
                if ($schema !== null) {
                    return [$schema, false];
                }
            }
        }

        // A `$fillable`-only name is NOT a floor here: "anything" is no answer for a path segment.
        return [$isKey ? $facts['keySchema'] : null, false];
    }

    /**
     * A recovered column type as a path segment: a plain scalar, `null` stripped off a nullable one
     * because a bound segment always carries a value. Anything else — a class, an array shape, an enum,
     * `unknown` — has no truthful single-scalar form, so it is refused.
     *
     * @return array<string, mixed>|null
     */
    private static function segmentSchema(DType $type): ?array
    {
        if ($type instanceof UnionT) {
            $type = $type->without(static fn (DType $member): bool => $member instanceof NullT);
        }

        return $type instanceof ScalarT ? ['type' => JsonTypes::forScalar($type->scalar)] : null;
    }

    /**
     * A schema fragment kept only when it types a single JSON scalar. `array`/`object` casts serialise
     * that way in a response body but can never be what a client puts in a URL segment.
     *
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>|null
     */
    private static function asPathSegment(?array $schema): ?array
    {
        $type = $schema['type'] ?? null;

        return is_string($type) && in_array($type, ['string', 'integer', 'number', 'boolean'], true)
            ? $schema
            : null;
    }

    /**
     * @return ModelFacts
     */
    public function facts(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return ['hidden' => [], 'visible' => [], 'appends' => [], 'casts' => [], 'classHidden' => [], 'fillable' => [], 'dates' => [], 'with' => [], 'timestamps' => false, 'softDeletes' => false, 'overridesSerializeDate' => false, 'keyName' => 'id', 'keySchema' => ['type' => 'integer']];
        }

        $reflection = new ReflectionClass($fqcn);
        $defaults = $reflection->getDefaultProperties();

        $traits = self::traits($fqcn);

        // $casts merged with the casts() method (Laravel 11+), the method winning on a key conflict —
        // mirrors HasAttributes::getCasts().
        $file = $reflection->getFileName();
        $casts = [...self::castMap($defaults['casts'] ?? []), ...$this->castsMethod->read($file === false ? null : $file)];

        return [
            'hidden' => self::stringList($defaults['hidden'] ?? []),
            'visible' => self::stringList($defaults['visible'] ?? []),
            'appends' => self::stringList($defaults['appends'] ?? []),
            'casts' => $casts,
            // Read through the one owner of the deny-list, not off reflection again: a second reader is
            // free to disagree with the one that reports an unmatched name ({@see SchemaIdentity}).
            'classHidden' => SchemaIdentity::hidden($fqcn),
            'fillable' => self::stringList($defaults['fillable'] ?? []),
            'dates' => self::stringList($defaults['dates'] ?? []),
            // `$with` relations are eager-loaded on every query, so they serialise on every response.
            'with' => self::stringList($defaults['with'] ?? []),
            // Timestamps default on unless the model sets `$timestamps = false`.
            'timestamps' => ($defaults['timestamps'] ?? true) !== false,
            'softDeletes' => in_array(self::SOFT_DELETES, $traits, true),
            // An override rewrites every date attribute's wire format, making it statically unknowable.
            'overridesSerializeDate' => self::overridesSerializeDate($reflection),
            'keyName' => is_string($defaults['primaryKey'] ?? null) ? $defaults['primaryKey'] : 'id',
            'keySchema' => self::keySchema($defaults, $traits),
        ];
    }

    /**
     * Whether the model declares its own `serializeDate()`. Laravel's default lives in the HasAttributes
     * concern, so anything declared outside `Illuminate\` is a user override.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private static function overridesSerializeDate(ReflectionClass $reflection): bool
    {
        if (! $reflection->hasMethod(self::SERIALIZE_DATE)) {
            return false;
        }

        return ! str_starts_with($reflection->getMethod(self::SERIALIZE_DATE)->getDeclaringClass()->getName(), 'Illuminate\\');
    }

    /**
     * The primary-key schema: `HasUuids`/`HasUlids` key on a string with the matching format, otherwise
     * `$keyType` decides between an integer and a plain string.
     *
     * @param  array<string, mixed>  $defaults
     * @param  list<string>  $traits
     * @return array<string, mixed>
     */
    private static function keySchema(array $defaults, array $traits): array
    {
        if (in_array(self::HAS_UUIDS, $traits, true)) {
            return ['type' => 'string', 'format' => 'uuid'];
        }
        if (in_array(self::HAS_ULIDS, $traits, true)) {
            return ['type' => 'string', 'format' => 'ulid'];
        }

        $keyType = $defaults['keyType'] ?? 'int';

        return $keyType === 'string' ? ['type' => 'string'] : ['type' => 'integer'];
    }

    /**
     * Every trait on the class and its parents — `class_uses_recursive` without instantiating.
     *
     * @return list<string>
     */
    private static function traits(string $fqcn): array
    {
        $traits = [];
        for ($class = $fqcn; $class !== false; $class = get_parent_class($class)) {
            foreach (class_uses($class) ?: [] as $trait) {
                $traits[$trait] = true;
            }
        }

        return array_keys($traits);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @return array<string, string>
     */
    private static function castMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $column => $cast) {
            if (is_string($column) && is_string($cast)) {
                $out[$column] = $cast;
            }
        }

        return $out;
    }
}
