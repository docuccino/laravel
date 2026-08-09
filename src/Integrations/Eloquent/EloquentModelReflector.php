<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Attributes\Hidden as DocuccinoHidden;
use Docuccino\Core\Inference\ClassMetadata;
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
     * disagree; anything unreflectable falls back to `integer`. A model that overrides `getRouteKeyName()`
     * to bind on some other column is out of scope — the PK schema is still the closest static answer.
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
