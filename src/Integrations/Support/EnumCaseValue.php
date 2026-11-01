<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use BackedEnum;
use Docuccino\Core\Extensions\Schema\EnumReflection;
use Docuccino\Core\Inference\ConstValue;
use UnitEnum;

/**
 * The value Laravel reads an enum case as wherever it takes one in place of a value — a choice rule's list,
 * `Rule::array()`'s keys, a query binding. Each goes through `Illuminate\Support\enum_value()`, which is a
 * backed case's backing value and a pure case's name, never a backed case's name. That value is written in
 * the enum's file, which keys whatever quotes it.
 */
final class EnumCaseValue
{
    /** The case's value, or null where it is not a case this build can reflect. */
    public static function of(ConstValue $case): string|int|null
    {
        $name = $case->caseName();

        return $name === null ? null : EnumReflection::valueOf((string) $case->class, $name);
    }

    /** The same value, for a case read as a live value — an attribute's argument. */
    public static function ofLive(UnitEnum $case): string|int
    {
        return $case instanceof BackedEnum ? $case->value : $case->name;
    }

    /** The file the case's enum is declared in, or null. */
    public static function file(ConstValue $case): ?string
    {
        return $case->isCase() ? EnumReflection::file((string) $case->class) : null;
    }
}
