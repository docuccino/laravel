<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

/**
 * Mints the SDK member names for a sort/include enum's values. A name is a pure function of its
 * value — `-total` → `TotalDesc`, `friends.pact` → `FriendsPact` — so adding a value never renames
 * a neighbour. Two values whose pretty names collide fall back to a strict spelling that encodes
 * the raw distinction (`FriendsDotPact`), and to a content-derived suffix in the last resort;
 * never a first-come counter, and never a partial or absent array — generators apply these by
 * index and without their own dedupe, so full-length distinct names are the honest degradation.
 */
final class ListValueNames
{
    /**
     * The minted names, parallel to `$values`.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function names(array $values): array
    {
        return self::resolve($values)['names'];
    }

    /**
     * The values whose pretty names collided and were re-minted — diagnostic material.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function collisions(array $values): array
    {
        return self::resolve($values)['collisions'];
    }

    /**
     * @param  list<string>  $values
     * @return array{names: list<string>, collisions: list<string>}
     */
    private static function resolve(array $values): array
    {
        $names = array_map(self::pretty(...), $values);

        $contested = array_keys(array_filter(array_count_values($names), static fn (int $count): bool => $count > 1));
        if ($contested === []) {
            return ['names' => $names, 'collisions' => []];
        }

        $collisions = [];
        foreach ($names as $index => $name) {
            if (in_array($name, $contested, true)) {
                $collisions[] = $values[$index];
                $names[$index] = self::strict($values[$index]);
            }
        }

        // Strict spelling can itself collide only on byte-identical remainders; a content suffix
        // keeps the array distinct without picking a winner.
        foreach (array_keys(array_filter(array_count_values($names), static fn (int $count): bool => $count > 1)) as $name) {
            foreach ($names as $index => $candidate) {
                if ($candidate === $name) {
                    $names[$index] .= '_'.substr(sha1($values[$index]), 0, 8);
                }
            }
        }

        return ['names' => $names, 'collisions' => $collisions];
    }

    /** `-total` → `TotalDesc`, `issued_at` → `IssuedAt`, `friends.pact` → `FriendsPact`. */
    private static function pretty(string $value): string
    {
        [$base, $suffix] = self::direction($value);

        $segments = preg_split('/[^A-Za-z0-9]+/', $base, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $name = implode('', array_map(ucfirst(...), $segments));

        return self::identifier($name).$suffix;
    }

    /** The raw distinction spelled out: `friends.pact` → `FriendsDotPact`, `-a_b` → `AUnderscoreBDesc`. */
    private static function strict(string $value): string
    {
        [$base, $suffix] = self::direction($value);

        $name = '';
        $capitalize = true;
        foreach (preg_split('//u', $base, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (ctype_alnum($char)) {
                $name .= $capitalize ? ucfirst($char) : $char;
                $capitalize = false;

                continue;
            }

            $name .= match ($char) {
                '.' => 'Dot',
                '-' => 'Dash',
                '_' => 'Underscore',
                default => 'X'.dechex(mb_ord($char)),
            };
            $capitalize = true;
        }

        return self::identifier($name).$suffix;
    }

    /**
     * A leading `-` is Spatie's descending marker, not a character to spell.
     *
     * @return array{0: string, 1: string}
     */
    private static function direction(string $value): array
    {
        return str_starts_with($value, '-') ? [substr($value, 1), 'Desc'] : [$value, ''];
    }

    /** Every minted name must be an identifier in the strictest target language. */
    private static function identifier(string $name): string
    {
        if ($name === '') {
            return 'Value';
        }

        return ctype_digit($name[0]) ? '_'.$name : $name;
    }
}
