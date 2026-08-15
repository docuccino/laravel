<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Docuccino\Core\Extensions\BuiltIn\JsonTypes;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use ReflectionClass;

/**
 * Reads the presentation facts a model declares — `$visible`/`$hidden`/`$appends`, `$casts`, a
 * class-level `#[Hidden]` — off the class's default property values. The model is never instantiated,
 * so no boot side effects and no DB access. These refine the columns {@see ClassMetadata} supplies.
 */
final class EloquentModelReflector
{
    public const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    public function __construct(
        private readonly CastsMethodReader $castsMethod = new CastsMethodReader,
    ) {}

    private const SOFT_DELETES = 'Illuminate\\Database\\Eloquent\\SoftDeletes';

    private const HAS_UUIDS = 'Illuminate\\Database\\Eloquent\\Concerns\\HasUuids';

    private const HAS_ULIDS = 'Illuminate\\Database\\Eloquent\\Concerns\\HasUlids';

    private const SERIALIZE_DATE = 'serializeDate';

    /** A concrete Eloquent model — the schema mapper's trigger. */
    public static function isModel(string $fqcn): bool
    {
        return $fqcn !== self::MODEL && is_a($fqcn, self::MODEL, true);
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
    public static function keySchemaFor(string $fqcn): array
    {
        if (! self::isModel($fqcn) || ! class_exists($fqcn)) {
            return ['type' => 'integer'];
        }

        $reflection = new ReflectionClass($fqcn);

        return self::keySchema($reflection->getDefaultProperties(), self::traits($fqcn));
    }

    /**
     * The schema for the NAMED column a `{post:slug}` parameter binds on, or null when nothing types it —
     * and null is the point: the route key's schema would say `integer` for a slug, which is worse than
     * saying nothing. Precedence mirrors {@see ModelSchema}'s so a column can't be documented one way in a
     * response and another way in the path: a uuid/ulid key beats a stale docblock, a `$casts` entry beats
     * the inferred type, and the engine's `@property` type is the floor.
     *
     * A column whose type can't be carried in a URL segment (an `array` cast, a `@property` naming a class)
     * is refused rather than emitted — the parameter is a path segment, not the serialised attribute.
     *
     * @return array<string, mixed>|null
     */
    public function columnSchemaFor(string $fqcn, string $column, ClassMetadata $metadata): ?array
    {
        if (! self::isModel($fqcn) || ! class_exists($fqcn)) {
            return null;
        }

        $facts = $this->facts($fqcn);
        $isKey = $column === $facts['keyName'];

        // HasUuids/HasUlids fix the key's format outright.
        if ($isKey && isset($facts['keySchema']['format'])) {
            return $facts['keySchema'];
        }

        $cast = $facts['casts'][$column] ?? null;
        if ($cast !== null) {
            $schema = self::asPathSegment(
                $facts['overridesSerializeDate'] && CastSchema::isDateCast($cast)
                    ? ['type' => 'string']
                    : CastSchema::forCast($cast),
            );
            if ($schema !== null) {
                return $schema;
            }
        }

        foreach ($metadata->properties as $property) {
            if ($property->name === $column) {
                $schema = self::segmentSchema($property->type);
                if ($schema !== null) {
                    return $schema;
                }
            }
        }

        // A `$dates` entry is a date-time column, ranked below the engine's types exactly as it is in a
        // response body. A `$fillable`-only name is deliberately NOT a floor here: it types the column
        // as "anything", which for a path segment is no answer at all.
        if (in_array($column, $facts['dates'], true)) {
            return $facts['overridesSerializeDate'] ? ['type' => 'string'] : ['type' => 'string', 'format' => 'date-time'];
        }

        return $isKey ? $facts['keySchema'] : null;
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
     * @return array{hidden: list<string>, visible: list<string>, appends: list<string>, casts: array<string, string>, classHidden: list<string>, fillable: list<string>, dates: list<string>, with: list<string>, timestamps: bool, softDeletes: bool, overridesSerializeDate: bool, keyName: string, keySchema: array<string, mixed>}
     */
    public function facts(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return ['hidden' => [], 'visible' => [], 'appends' => [], 'casts' => [], 'classHidden' => [], 'fillable' => [], 'dates' => [], 'with' => [], 'timestamps' => false, 'softDeletes' => false, 'overridesSerializeDate' => false, 'keyName' => 'id', 'keySchema' => ['type' => 'integer']];
        }

        $reflection = new ReflectionClass($fqcn);
        $defaults = $reflection->getDefaultProperties();

        $classHidden = [];
        foreach ($reflection->getAttributes(DocuccinoHidden::class) as $attribute) {
            $classHidden = [...$classHidden, ...$attribute->newInstance()->properties];
        }

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
            'classHidden' => $classHidden,
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
