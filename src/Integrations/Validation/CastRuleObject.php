<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Inference\ConstValue;
use Illuminate\Validation\ValidationRuleParser;
use ReflectionClass;
use ReflectionMethod;
use Stringable;
use Throwable;

/**
 * One of Laravel's own rule objects that its validator reads as the rule string it casts to, rather than
 * running it as a check: `Rule::string()->min(2)`, `Rule::acceptedIf('kind', 'business')`. Written with values
 * at the call, it is built and read by Laravel's own parser. One it reads by its state instead (`Password`,
 * `File`, `Email`) is built the same way and read as {@see LiveRuleValue} reads it. Building runs only Laravel's
 * constructor, `Rule::` static, the rule's own statics that read nothing registered, and builder methods, on
 * literal values: never a macro, a callable or a model, so nothing of the application's runs.
 */
final class CastRuleObject
{
    private const RULES = 'Illuminate\\Validation\\Rules\\';

    /**
     * The objects Laravel runs as rules of their own rather than casting them to a string
     * ({@see ValidationRuleParser}).
     */
    private const RUN_AS_RULES = [
        'Illuminate\\Contracts\\Validation\\Rule',
        'Illuminate\\Contracts\\Validation\\ValidationRule',
        'Illuminate\\Contracts\\Validation\\InvokableRule',
        'Illuminate\\Contracts\\Validation\\CompilableRules',
    ];

    /** The trait that resolves a model class written as a table by constructing the model, application code. */
    private const DATABASE_RULE = 'Illuminate\\Validation\\Rules\\DatabaseRule';

    /** Rule objects whose constructor reads an array's keys, built only from an array whose keys the fold read. */
    private const KEYED_CONSTRUCTORS = ['Illuminate\\Validation\\Rules\\Dimensions'];

    /**
     * The statics of a rule class that build from their arguments alone. Its others (`Password::defaults()`,
     * `File::default()`) answer with what the application registered, which is its code.
     */
    private const OWN_FACTORIES = [
        'illuminate\\validation\\rules\\password' => ['min'],
        'illuminate\\validation\\rules\\file' => ['image', 'types'],
    ];

    /** Whether Laravel validates with a rule object of this class as its string: one of its own, no check itself. */
    public static function casts(string $class): bool
    {
        $class = ltrim($class, '\\');
        if (! str_starts_with($class, self::RULES) || ! class_exists($class) || ! is_a($class, Stringable::class, true)) {
            return false;
        }

        foreach (self::RUN_AS_RULES as $contract) {
            if (is_a($class, $contract, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The framework rule object a `new` or a static call written in a rules list builds, chained calls
     * included, where every value is a literal and every step is one this builds; null otherwise.
     */
    public static function build(ConstValue $written): ?object
    {
        return match (true) {
            $written->isInstance() => self::buildNew((string) $written->class, $written->args, $written->chain),
            $written->isDescriptor() => self::buildFactory((string) $written->factory, $written->args, $written->chain),
            default => null,
        };
    }

    /**
     * The rule strings Laravel's validator reads a rule object as, split where its own parser splits them.
     *
     * @return list<string>
     */
    public static function strings(Stringable $rule): array
    {
        $rules = (new ValidationRuleParser([]))->explode(['field' => [$rule]])->rules;
        $parsed = is_array($rules) && is_array($rules['field'] ?? null) ? $rules['field'] : [];

        $strings = [];
        foreach ($parsed as $piece) {
            $string = is_string($piece) || $piece instanceof Stringable ? trim((string) $piece) : '';
            if ($string !== '') {
                $strings[] = $string;
            }
        }

        return $strings;
    }

    /**
     * @param  list<ConstValue>  $args
     * @param  list<array{method: string, args: list<ConstValue>}>  $chain
     */
    private static function buildNew(string $class, array $args, array $chain): ?object
    {
        $class = ltrim($class, '\\');
        if (! class_exists($class) || ! self::buildable($class) || ! self::keysRead($class, $args)) {
            return null;
        }

        $constructor = (new ReflectionClass($class))->getConstructor();
        $values = self::literals($args);
        if ($values === null || ($constructor !== null && ! self::takesNoCallable($constructor))) {
            return null;
        }

        try {
            $rule = new $class(...$values);
        } catch (Throwable) {
            return null;
        }

        return self::chained($rule, $chain);
    }

    /**
     * @param  list<ConstValue>  $args
     * @param  list<array{method: string, args: list<ConstValue>}>  $chain
     */
    private static function buildFactory(string $factory, array $args, array $chain): ?object
    {
        $method = RuleFactories::staticOf($factory);
        $class = $method === null ? null : RuleFactories::builtClass($method->getName());
        if ($method === null) {
            $method = self::ownFactory($factory);
            $class = $method?->class;
        }

        if ($method === null || $class === null || ! self::buildable($class) || ! self::keysRead($class, $args)) {
            return null;
        }

        $rule = self::invoke($method, null, $args);

        return is_object($rule) && self::buildable($rule::class) ? self::chained($rule, $chain) : null;
    }

    /**
     * The rule strings a built rule object is cast to, or null where Laravel does not cast it.
     *
     * @return list<string>|null
     */
    public static function castStrings(?object $rule): ?array
    {
        return $rule instanceof Stringable && self::casts($rule::class) ? self::strings($rule) : null;
    }

    /** A static of a rule class that builds from its arguments alone ({@see OWN_FACTORIES}). */
    private static function ownFactory(string $factory): ?ReflectionMethod
    {
        [$class, $name] = array_pad(explode('::', ltrim($factory, '\\'), 2), 2, '');
        if (! in_array(strtolower($name), self::OWN_FACTORIES[strtolower($class)] ?? [], true) || ! method_exists($class, $name)) {
            return null;
        }

        $method = new ReflectionMethod($class, $name);

        return $method->isPublic() && $method->isStatic() ? $method : null;
    }

    /**
     * Whether a constructor that reads its array's keys is handed only arrays whose keys the fold read. Any
     * other is built from the values alone, which is all it reads.
     *
     * @param  list<ConstValue>  $args
     */
    private static function keysRead(string $class, array $args): bool
    {
        if (! in_array($class, self::KEYED_CONSTRUCTORS, true)) {
            return true;
        }

        foreach ($args as $arg) {
            if ($arg->isArray() && $arg->items !== [] && $arg->keys === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{method: string, args: list<ConstValue>}>  $chain
     */
    private static function chained(object $rule, array $chain): ?object
    {
        foreach ($chain as $call) {
            // A method the class does not declare is a macro, which is the application's code, and so is what a
            // magic method such as `__call()` dispatches to: none is a builder step.
            $reflection = new ReflectionClass($rule);
            if (! $reflection->hasMethod($call['method']) || str_starts_with($call['method'], '__')) {
                return null;
            }

            $method = $reflection->getMethod($call['method']);
            $rule = $method->isPublic() && ! $method->isStatic() ? self::invoke($method, $rule, $call['args']) : null;
            if (! is_object($rule) || ! self::buildable($rule::class)) {
                return null;
            }
        }

        return $rule;
    }

    /**
     * What a method returns for the literal values written at its call, or null where one is no literal, it
     * takes a callable or it throws.
     *
     * @param  list<ConstValue>  $args
     */
    private static function invoke(ReflectionMethod $method, ?object $on, array $args): mixed
    {
        $values = self::literals($args);
        if ($values === null || count($values) < $method->getNumberOfRequiredParameters() || ! self::takesNoCallable($method)) {
            return null;
        }

        try {
            return $method->invoke($on, ...$values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether this builds an object of the class: one Laravel casts to a rule string, or one {@see LiveRuleValue}
     * reads by its state, that resolves no model.
     */
    private static function buildable(string $class): bool
    {
        if (! self::casts($class) && ! LiveRuleValue::readsState($class)) {
            return false;
        }

        for ($scope = $class; $scope !== false; $scope = get_parent_class($scope)) {
            if (in_array(self::DATABASE_RULE, class_uses($scope) ?: [], true)) {
                return false;
            }
        }

        return true;
    }

    /** Whether no parameter is declared to take a callable, which a literal string can be. */
    private static function takesNoCallable(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = strtolower((string) $parameter->getType());
            if (str_contains($type, 'callable') || str_contains($type, 'closure')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The values a list of arguments states, or null unless each is a scalar, an array of them with the keys
     * written, or a framework rule object this builds ({@see build()}).
     *
     * @param  list<ConstValue>  $args
     * @return list<mixed>|null
     */
    private static function literals(array $args): ?array
    {
        $values = [];
        foreach ($args as $arg) {
            $value = match (true) {
                $arg->isScalar() => [$arg->scalar],
                $arg->isArray() => self::literalArray($arg),
                default => self::nested($arg),
            };

            if ($value === null) {
                return null;
            }

            $values[] = $value[0];
        }

        return $values;
    }

    /**
     * The array a literal array states, each item at the key written beside it, wrapped; null where an item is
     * no literal.
     *
     * @return array{0: array<array-key, mixed>}|null
     */
    private static function literalArray(ConstValue $array): ?array
    {
        $items = self::literals($array->items);
        if ($items === null) {
            return null;
        }

        $built = [];
        foreach ($items as $index => $item) {
            $key = $array->keys[$index] ?? null;
            if ($key === null) {
                $built[] = $item;
            } else {
                $built[$key] = $item;
            }
        }

        return [$built];
    }

    /**
     * A rule object written as an argument, built, wrapped; null where it is none this builds.
     *
     * @return array{0: object}|null
     */
    private static function nested(ConstValue $written): ?array
    {
        $rule = self::build($written);

        return $rule === null ? null : [$rule];
    }
}
