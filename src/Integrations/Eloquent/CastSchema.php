<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

/**
 * Maps an Eloquent `$casts` entry to the JSON Schema fragment it serialises to: datetime casts get a
 * `date-time`/`date` format honouring a `datetime:FORMAT` parameter, native casts fix a type,
 * decimal/hashed stay strings, `array`/`collection`/`json` admit object OR array, and
 * `encrypted:<inner>` decrypts-then-casts to the inner type.
 *
 * Anything enum-valued returns null here and is routed through the Enum integration by
 * {@see ModelSchema}, which owns that machinery — a backed-enum cast, `AsEnumCollection:Enum` and
 * `AsEnumArrayObject:Enum` (whose enum parameter {@see enumCollectionEnum()} exposes). An unrecognised
 * custom caster also returns null, leaving the column on its inferred type.
 */
final class CastSchema
{
    private const AS_NAMESPACE = 'Illuminate\\Database\\Eloquent\\Casts\\';

    /**
     * Built-in `As*` class casts with a fixed serialised shape. The `$casts` value is the FQCN, possibly
     * with a trailing `:arg` this table ignores.
     *
     * @var array<string, array<string, mixed>>
     */
    private const CLASS_CASTS = [
        self::AS_NAMESPACE.'AsStringable' => ['type' => 'string'],
        self::AS_NAMESPACE.'AsUri' => ['type' => 'string'],
        self::AS_NAMESPACE.'AsHtmlString' => ['type' => 'string'],
        self::AS_NAMESPACE.'AsFluent' => ['type' => 'object'],
        self::AS_NAMESPACE.'AsArrayObject' => ['type' => 'object'],
        self::AS_NAMESPACE.'AsCollection' => ['type' => 'array'],
        // Decrypt-THEN-cast: these serialise as the decoded JSON value, never the ciphertext string.
        self::AS_NAMESPACE.'AsEncryptedArrayObject' => ['type' => 'object'],
        self::AS_NAMESPACE.'AsEncryptedCollection' => ['type' => 'array'],
    ];

    /** The enum-valued `As*` class casts — an array of the parameterised enum's values. */
    private const AS_ENUM_COLLECTION = [
        self::AS_NAMESPACE.'AsEnumCollection',
        self::AS_NAMESPACE.'AsEnumArrayObject',
    ];

    private const DATE_CASTS = [
        'datetime',
        'immutable_datetime',
        'custom_datetime',
        'date',
        'immutable_date',
    ];

    /**
     * The schema fragment for a cast, or null to fall back to the inferred column type.
     *
     * @return array<string, mixed>|null
     */
    public static function forCast(string $cast): ?array
    {
        $parts = explode(':', $cast, 2);
        $base = $parts[0];
        $parameter = $parts[1] ?? null;

        // Class casts match on the FQCN case-sensitively, before the native table lowercases the base.
        if (isset(self::CLASS_CASTS[$base])) {
            return self::CLASS_CASTS[$base];
        }

        // `encrypted:<inner>` serialises as the inner type, not an opaque string.
        if (strtolower($base) === 'encrypted' && $parameter !== null && $parameter !== '') {
            return self::forCast($parameter);
        }

        return match (strtolower($base)) {
            'datetime', 'immutable_datetime', 'custom_datetime' => self::datetime($parameter),
            'date', 'immutable_date' => ['type' => 'string', 'format' => 'date'],
            'timestamp' => ['type' => 'integer'],
            'boolean', 'bool' => ['type' => 'boolean'],
            'integer', 'int' => ['type' => 'integer'],
            'real', 'float', 'double' => ['type' => 'number'],
            'decimal' => ['type' => 'string'],
            'string', 'encrypted', 'hashed' => ['type' => 'string'],
            // Decodes to whatever was stored: an assoc array is an object, a list is an array, so both.
            'array', 'collection', 'json' => ['type' => ['array', 'object']],
            'object' => ['type' => 'object'],
            default => null,
        };
    }

    /**
     * A `datetime` cast honouring its `datetime:FORMAT` parameter. ISO forms with a time are `date-time`,
     * the ISO date-only form is `date`, and a bespoke format is a plain string with the format noted in
     * the description — better than a `format` claim that would be wrong.
     *
     * @return array<string, mixed>
     */
    private static function datetime(?string $format): array
    {
        if ($format === null || $format === '') {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if (in_array($format, ['Y-m-d\\TH:i:sP', 'Y-m-d\\TH:i:s.uP', 'Y-m-d H:i:s', 'c'], true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if ($format === 'Y-m-d') {
            return ['type' => 'string', 'format' => 'date'];
        }

        return ['type' => 'string', 'description' => sprintf('Serialized using the date format "%s".', $format)];
    }

    /** Whether a cast value names an enum. */
    public static function isEnum(string $cast): bool
    {
        $base = explode(':', $cast, 2)[0];

        return enum_exists($base);
    }

    /** The enum FQCN of an `AsEnumCollection:Enum` / `AsEnumArrayObject:Enum` cast. */
    public static function enumCollectionEnum(string $cast): ?string
    {
        $parts = explode(':', $cast, 2);
        $enum = $parts[1] ?? null;

        return in_array($parts[0], self::AS_ENUM_COLLECTION, true) && $enum !== null && $enum !== ''
            ? $enum
            : null;
    }

    /**
     * Whether a cast serialises through `serializeDate()` — a model overriding that method makes these
     * formats unknowable. `timestamp` is excluded: it serialises as a unix integer, bypassing the hook.
     */
    public static function isDateCast(string $cast): bool
    {
        return in_array(strtolower(explode(':', $cast, 2)[0]), self::DATE_CASTS, true);
    }
}
