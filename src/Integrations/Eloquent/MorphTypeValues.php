<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * The values a `morphTo`'s type column can hold — what `getMorphClass()` answers for each model the
 * relation can point at — or null wherever that set is open, since an enum short of what the server
 * writes marks a real response invalid. When the set counts as closed: docs/design/uir-and-extensions.md
 * §"Morph type columns".
 *
 * @phpstan-type MorphTypeAnswer array{values: list<string>|null, classes: list<string>}
 */
final class MorphTypeValues
{
    /**
     * The values, sorted so the list is a function of the set, beside every class whose declaration
     * the answer read — the caller's cache dependencies.
     *
     * @param  list<class-string>|null  $targets  the relation's declared models; null when it declares none
     * @return MorphTypeAnswer
     */
    public static function of(?array $targets): array
    {
        $map = Relation::morphMap();
        $enforced = Relation::requiresMorphMap();

        if (! $enforced) {
            return $targets === null ? ['values' => null, 'classes' => []] : self::closedByFinality($targets, $map);
        }

        // Every mapped class is read either way: a class becoming a target's subclass changes the answer.
        $mapped = array_values(array_unique(array_filter($map, 'is_string')));
        $read = array_values(array_unique([...$mapped, ...($targets ?? [])]));
        $candidates = $targets === null
            ? $mapped
            : array_values(array_unique([...$targets, ...array_filter($mapped, static fn (string $class): bool => self::isOneOf($class, $targets))]));

        $values = [];
        foreach ($candidates as $class) {
            if (! class_exists($class) || self::isAbstract($class)) {
                continue;
            }
            if (self::overridesMorphClass($class)) {
                return ['values' => null, 'classes' => $read];
            }

            $value = self::morphClass($class, $map, true);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return ['values' => self::sorted($values), 'classes' => $read];
    }

    /**
     * Without enforcement: each target writes its alias or its class name, and the set is closed only
     * where no target can have a subclass writing a third.
     *
     * @param  list<class-string>  $targets
     * @param  array<array-key, mixed>  $map
     * @return MorphTypeAnswer
     */
    private static function closedByFinality(array $targets, array $map): array
    {
        $values = [];
        foreach ($targets as $class) {
            if (! self::isFinal($class) || self::overridesMorphClass($class)) {
                return ['values' => null, 'classes' => $targets];
            }

            $value = self::morphClass($class, $map, false);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return ['values' => self::sorted($values), 'classes' => $targets];
    }

    /**
     * `getMorphClass()` as the framework answers it: the FIRST alias the map holds for the class, else
     * the class name — or nothing, where enforcement throws instead.
     *
     * @param  array<array-key, mixed>  $map
     */
    private static function morphClass(string $class, array $map, bool $enforced): ?string
    {
        $alias = array_search($class, $map, true);
        if ($alias !== false) {
            // An alias may be an int key; the column holds it as a string.
            return (string) $alias;
        }

        return $class === Pivot::class || ! $enforced ? $class : null;
    }

    /** @param  list<class-string>  $targets */
    private static function isOneOf(string $class, array $targets): bool
    {
        foreach ($targets as $target) {
            if (is_a($class, $target, true)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the class's `getMorphClass()` is its own rather than the framework's. */
    private static function overridesMorphClass(string $class): bool
    {
        try {
            return ! str_starts_with((new ReflectionMethod($class, 'getMorphClass'))->getDeclaringClass()->getName(), 'Illuminate\\');
        } catch (Throwable) {
            return true;
        }
    }

    /** @param  class-string  $class */
    private static function isAbstract(string $class): bool
    {
        try {
            return ! (new ReflectionClass($class))->isInstantiable();
        } catch (Throwable) {
            return true;
        }
    }

    /** @param  class-string  $class */
    private static function isFinal(string $class): bool
    {
        try {
            $reflection = new ReflectionClass($class);

            return $reflection->isFinal() && $reflection->isInstantiable();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * An empty set is not an answer: an `enum` must hold a value, and a column nothing can write is
     * one this build has misread rather than one the document can describe.
     *
     * @param  list<string>  $values
     * @return list<string>|null
     */
    private static function sorted(array $values): ?array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values === [] ? null : $values;
    }
}
