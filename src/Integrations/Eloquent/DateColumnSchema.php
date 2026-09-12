<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Docuccino\Laravel\Integrations\Support\DateWireFormat;

/**
 * What a model's date attribute publishes, and the ONE place that decides it — a response body column and
 * a route-bound path segment both come through here. The dates it speaks for are the ones
 * `serializeDate()` really writes ({@see CastSchema::serializesThroughDateHook()}), whatever a
 * `@property` tag or a cast gave the column, so the tag never decides the shape. Design:
 * docs/design/inference-embedding.md §"Eloquent column source".
 *
 * @phpstan-import-type ModelFacts from EloquentModelReflector
 */
final class DateColumnSchema
{
    /** What `Model::serializeDate()` writes, so the `date-time` claim is read off bytes, not asserted. */
    public const DEFAULT_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * The framework's own date columns, in the order a response carries them.
     *
     * @var list<string>
     */
    public const TIMESTAMPS = ['created_at', 'updated_at'];

    public const DELETED_AT = 'deleted_at';

    /**
     * The hook's own form, or a bare string where an override took the `format` away —
     * {@see formatGivenUp()} answers which, so no site publishes the weakened shape unable to report it.
     *
     * @param  ModelFacts  $facts
     * @return array<string, mixed>
     */
    public static function schema(array $facts): array
    {
        return $facts['overridesSerializeDate']
            ? ['type' => 'string']
            : DateWireFormat::serializedSchema(self::DEFAULT_FORMAT);
    }

    /**
     * Whether {@see schema()} gives the `format` up for this model — the loss
     * `eloquent.custom-date-serialization` reports.
     *
     * @param  ModelFacts  $facts
     */
    public static function formatGivenUp(array $facts): bool
    {
        return $facts['overridesSerializeDate'];
    }

    /**
     * Whether this policy decides the column: a hook-governed cast, a `$dates` entry, or a framework
     * timestamp / soft-delete column the model really has.
     *
     * @param  ModelFacts  $facts
     */
    public static function isAttribute(string $column, array $facts): bool
    {
        $cast = $facts['casts'][$column] ?? null;

        return ($cast !== null && CastSchema::serializesThroughDateHook($cast))
            || in_array($column, $facts['dates'], true)
            || ($facts['timestamps'] && in_array($column, self::TIMESTAMPS, true))
            || ($facts['softDeletes'] && $column === self::DELETED_AT);
    }
}
