<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation;

use Docuccino\Core\Extensions\Schema\DeclaredReturnType;
use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\ScalarT;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * What a `Rule::` static builds, read off the installed `Illuminate\Validation\Rule`, so a static Laravel
 * adds is answered for the day it ships. A static declared to return a rule string is the string it
 * returns, got by calling it: it is Laravel's own code, it runs nothing of the application's, and the
 * app's `composer.lock` already keys the build, so an upgrade re-reads it.
 */
final class RuleFactories
{
    private const RULE = 'Illuminate\\Validation\\Rule';

    /** @var array<string, ReflectionMethod|null> */
    private static array $methods = [];

    /** @var array<string, string|null> */
    private static array $returns = [];

    /**
     * The class a static declared on `Rule` says it returns, or null where it returns no one class — a
     * string, a union, nothing — or is not declared there (a macro).
     */
    public static function builtClass(string $method): ?string
    {
        $returns = self::returns($method);

        return $returns === null || $returns === 'string' || ! class_exists($returns) ? null : $returns;
    }

    /**
     * The rule string a `Rule::` call returns, where Laravel declares the static to return one and every
     * argument is a value written at the call. Null otherwise.
     *
     * @param  string  $factory  `Illuminate\Validation\Rule::nullable`
     * @param  list<ConstValue>  $args
     */
    public static function ruleString(string $factory, array $args): ?string
    {
        $factory = ltrim($factory, '\\');
        $separator = strrpos($factory, '::');
        if ($separator === false || strcasecmp(substr($factory, 0, $separator), self::RULE) !== 0) {
            return null;
        }

        $method = substr($factory, $separator + 2);
        $reflection = self::method($method);
        if ($reflection === null || self::returns($method) !== 'string' || count($args) < $reflection->getNumberOfRequiredParameters()) {
            return null;
        }

        $values = [];
        foreach ($args as $arg) {
            if (! $arg->isScalar()) {
                return null;
            }

            $values[] = $arg->scalar;
        }

        try {
            $rule = $reflection->invoke(null, ...$values);
        } catch (Throwable) {
            return null;
        }

        return is_string($rule) ? $rule : null;
    }

    /** `'string'`, a class name, or null for any other declared return. */
    private static function returns(string $method): ?string
    {
        $key = strtolower($method);
        if (array_key_exists($key, self::$returns)) {
            return self::$returns[$key];
        }

        $reflection = self::method($method);
        $declared = $reflection === null ? null : DeclaredReturnType::of($reflection);
        $native = $reflection?->getReturnType();

        return self::$returns[$key] = match (true) {
            $declared instanceof ClassT => $declared->fqcn,
            $declared instanceof ScalarT => $declared->scalar === ScalarT::STRING ? 'string' : null,
            $declared === null && $native instanceof ReflectionNamedType => $native->getName() === 'string' || ! $native->isBuiltin() ? $native->getName() : null,
            default => null,
        };
    }

    /** A public static of the installed `Rule`, or null for a macro or a name it does not have. */
    private static function method(string $method): ?ReflectionMethod
    {
        $key = strtolower($method);
        if (array_key_exists($key, self::$methods)) {
            return self::$methods[$key];
        }

        if (! class_exists(self::RULE) || ! method_exists(self::RULE, $method)) {
            return self::$methods[$key] = null;
        }

        $reflection = new ReflectionMethod(self::RULE, $method);

        return self::$methods[$key] = $reflection->isStatic() && $reflection->isPublic() ? $reflection : null;
    }
}
