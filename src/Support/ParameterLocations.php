<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Document\Parameter;

/**
 * How a declaration's `in:` is read against the parameter locations, and how a refusal quotes them back
 * at the author. The set itself is an OAS fact and lives in {@see Parameter::LOCATIONS}; only the
 * reading of an author's word for it belongs here.
 *
 * Stated once because two readers of one author-written word have to agree: `#[IgnoreParam(in: 'Query')]`
 * and `#[RenamedParameter(in: 'Query')]` either both mean `query` or the vocabulary is inconsistent in a
 * way nobody can look up. Case is folded for the same reason — a spelling the tool understands is not
 * worth making an author check the docs for — and a value naming no location at all comes back as null
 * so the caller can report it rather than guess.
 *
 * @internal
 */
final class ParameterLocations
{
    /**
     * The legal set as the author who wrote something else reads it: alphabetical, which is an order
     * they can predict, rather than the publication order {@see Parameter::LOCATIONS} carries.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $all = Parameter::LOCATIONS;
        sort($all, SORT_STRING);

        return $all;
    }

    /** `$in` as the document spells it, or null where it names no location. */
    public static function read(string $in): ?string
    {
        $normalized = strtolower(trim($in));

        return in_array($normalized, Parameter::LOCATIONS, true) ? $normalized : null;
    }

    /** The legal set as a diagnostic quotes it. */
    public static function quoted(): string
    {
        return '`'.implode('`, `', self::all()).'`';
    }
}
