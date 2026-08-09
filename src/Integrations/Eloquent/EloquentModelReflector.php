<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Docuccino\Core\Inference\ClassMetadata;
use ReflectionClass;

/**
 * Reads the presentation facts an Eloquent model declares — `$visible`/`$hidden`/`$appends`, the
 * `$casts` map, and any class-level `#[Hidden]` list — via native reflection of the class's default
 * property values (never instantiating the model, so no boot side effects or DB access). These
 * refine the column set the engine's {@see ClassMetadata} supplies.
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

    /** Whether an FQCN is a concrete Eloquent model (the schema mapper's trigger). */
    public static function isModel(string $fqcn): bool
    {
        return $fqcn !== self::MODEL && is_a($fqcn, self::MODEL, true);
    }

    /**
     * The route-key schema for a bound model, without the full {@see facts()} pass — the primary-key
     * column schema a `{model}` path parameter resolves to (uuid/ulid/int + format). Reuses the same
     * {@see keySchema()} the model mapper does (never duplicated), so a bound path param and the
     * model's own key column can never disagree. A non-model / unreflectable FQCN degrades to the
     * historical `integer` default. (A model overriding `getRouteKeyName()` to bind on a non-key column
     * is out of scope — its PK schema is still the closest static answer.)
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

        // The cast map is the $casts property merged with the casts() method (Laravel 11+ default),
        // the latter winning on a key conflict — mirroring HasAttributes::getCasts().
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
            // Relations named in `$with` are eager-loaded on every query, so they serialise on every
            // response (nested model schemas keyed by the snake-cased relation name).
            'with' => self::stringList($defaults['with'] ?? []),
            // Timestamps default on unless the model sets `$timestamps = false`.
            'timestamps' => ($defaults['timestamps'] ?? true) !== false,
            'softDeletes' => in_array(self::SOFT_DELETES, $traits, true),
            // A model overriding serializeDate() rewrites every date attribute's wire format, making it
            // statically unknowable — the date/datetime cast claims are weakened to a plain string.
            'overridesSerializeDate' => self::overridesSerializeDate($reflection),
            'keyName' => is_string($defaults['primaryKey'] ?? null) ? $defaults['primaryKey'] : 'id',
            'keySchema' => self::keySchema($defaults, $traits),
        ];
    }

    /**
     * Whether the model declares its own `serializeDate()` — i.e. the method's declaring class is not
     * one of Illuminate's (the default lives in the HasAttributes concern).
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
     * The primary-key column schema: a `HasUuids`/`HasUlids` model keys on a string with the matching
     * format; otherwise an incrementing integer key, or a plain string for a non-incrementing string
     * key. Only ever applied to the key column, and only when a trait/keyType makes it authoritative.
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
     * Every trait used by the class and its parents (the `class_uses_recursive` equivalent), read via
     * reflection without instantiating.
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
