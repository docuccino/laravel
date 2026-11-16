<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Laravel\Integrations\FormRequest\ConstValueToRules;
use ReflectionMethod;
use ReflectionObject;
use Stringable;
use UnitEnum;

/**
 * A rule value that already exists — an attribute's argument, built by PHP — as the {@see ConstValue} the
 * `new` that built it folds to, so {@see ConstValueToRules} reads `#[Rule(new Enum(X::class))]` exactly as it
 * reads `new Enum(X::class)` in a rules array. A framework rule object keeps its constructor's arguments in
 * its own state, and is read back from there. One Laravel casts to a rule string ({@see CastRuleObject}) is that
 * string, and one it validates by its state ({@see readsState()}) the rules it runs; any other object is its
 * class alone.
 */
final class LiveRuleValue
{
    private const RULES = 'Illuminate\\Validation\\Rules\\';

    /**
     * The properties a framework rule object keeps its constructor's arguments in, in argument order, for
     * every object {@see ConstValueToRules} reads by its arguments.
     */
    private const ARGUMENTS = [
        self::RULES.'Enum' => ['type'],
        self::RULES.'In' => ['values'],
        self::RULES.'NotIn' => ['values'],
        self::RULES.'ArrayRule' => ['keys'],
        // Read by name alone: the table and column decide nothing the document states.
        self::RULES.'Exists' => [],
        self::RULES.'Unique' => [],
        // Read by condition, never cast: a cast calls a closure condition, which is the application's code
        // answering for one request.
        self::RULES.'ExcludeIf' => ['condition'],
        self::RULES.'ExcludeUnless' => ['condition'],
        self::RULES.'RequiredIf' => ['condition'],
        self::RULES.'RequiredUnless' => ['condition'],
        self::RULES.'ProhibitedIf' => ['condition'],
        self::RULES.'ProhibitedUnless' => ['condition'],
        self::RULES.'ExcludeWith' => ['anotherField'],
        self::RULES.'ExcludeWithout' => ['fields'],
    ];

    /**
     * Where `->only()`/`->except()` leave an enum rule's cases. Laravel consults `except` only while `only`
     * is empty, so the first non-empty one is the whole narrowing.
     */
    private const ENUM_SELECTORS = ['only', 'except'];

    /** The checks a `Password` runs on the value after its own rules, none of which a schema states. */
    private const PASSWORD_CHECKS = ['mixedCase', 'letters', 'numbers', 'symbols', 'uncompromised'];

    private const PASSWORD = self::RULES.'Password';

    /**
     * Framework rule objects that validate the value with the rule list their state builds, and nothing else,
     * by the method that builds it.
     */
    private const BUILT_RULES = [
        self::RULES.'File' => 'buildValidationRules',
        self::RULES.'Email' => 'buildValidationRules',
    ];

    public static function of(mixed $value): ConstValue
    {
        return match (true) {
            $value === null || is_scalar($value) => ConstValue::scalar($value),
            $value instanceof UnitEnum => ConstValue::enumCase($value::class, $value->name),
            is_array($value) => ConstValue::array(array_map(self::of(...), array_values($value)), array_is_list($value) ? null : array_keys($value)),
            is_object($value) => self::object($value),
            default => ConstValue::unknown('a value no rule list can hold'),
        };
    }

    /**
     * Whether Laravel validates with a framework rule object of this class by the rules its state holds, which
     * this reads: a `Password`, or one that builds a rule list ({@see BUILT_RULES}).
     */
    public static function readsState(string $class): bool
    {
        $class = ltrim($class, '\\');

        return $class === self::PASSWORD || self::builder($class) !== null;
    }

    private static function object(object $rule): ConstValue
    {
        $class = $rule::class;
        if ($class === self::PASSWORD) {
            return self::password($rule);
        }

        $builder = self::builder($class);
        if ($builder !== null) {
            return self::built($rule, $builder);
        }

        if (! isset(self::ARGUMENTS[$class]) && $rule instanceof Stringable && CastRuleObject::casts($class)) {
            return ConstValue::scalar((string) $rule);
        }

        $args = [];
        foreach (self::ARGUMENTS[$class] ?? [] as $property) {
            $args[] = self::state($rule, $property);
        }

        $value = ConstValue::instance($class, $args);
        if ($class !== self::RULES.'Enum') {
            return $value;
        }

        foreach (self::ENUM_SELECTORS as $selector) {
            $cases = self::state($rule, $selector);
            if ($cases->items !== []) {
                return $value->withChainedCall($selector, [$cases]);
            }
        }

        return $value;
    }

    /**
     * A `Password` as the rules Laravel validates the value with first — `string`, `min`, any `max`, and the
     * rules it was handed — then, where it also runs a check of its own on the value (letters, symbols, a
     * breach lookup), itself, which the fold leaves out as a rule it cannot read.
     */
    private static function password(object $rule): ConstValue
    {
        $items = [ConstValue::scalar('string')];
        foreach (['min', 'max'] as $bound) {
            $value = self::state($rule, $bound)->scalar;
            if (is_int($value) && $value > 0) {
                $items[] = ConstValue::scalar($bound.':'.$value);
            }
        }

        $items = [...$items, ...self::state($rule, 'customRules')->items];

        foreach (self::PASSWORD_CHECKS as $check) {
            if (self::state($rule, $check)->scalar !== false) {
                // Unbuildable, so the fold names it rather than reading it again.
                $items[] = ConstValue::instance($rule::class, [ConstValue::unknown('the checks a password runs on the value')]);

                break;
            }
        }

        return ConstValue::array($items);
    }

    /** The method one of Laravel's own rule classes builds its rule list with, or null where it builds none. */
    private static function builder(string $class): ?string
    {
        foreach (self::BUILT_RULES as $parent => $method) {
            if (str_starts_with($class, self::RULES) && is_a($class, $parent, true) && method_exists($class, $method)) {
                return $method;
            }
        }

        return null;
    }

    /**
     * A rule object as the rules it validates the value with — `File::types(['pdf'])->max(1024)` as `file`,
     * `mimes:pdf` and `max:1024` — built by its own method, so each reads as that rule string does.
     */
    private static function built(object $rule, string $builder): ConstValue
    {
        $rules = (new ReflectionMethod($rule, $builder))->invoke($rule);

        return is_array($rules)
            ? ConstValue::array(array_map(self::of(...), array_values($rules)))
            : ConstValue::instance($rule::class, [ConstValue::unknown('a rule object whose rules this build cannot read')]);
    }

    /** One property of a rule object's state, or unknown where the installed class keeps no such property. */
    private static function state(object $rule, string $property): ConstValue
    {
        $reflection = new ReflectionObject($rule);

        return $reflection->hasProperty($property)
            ? self::of($reflection->getProperty($property)->getValue($rule))
            : ConstValue::unknown('a rule object whose state this build cannot read');
    }
}
