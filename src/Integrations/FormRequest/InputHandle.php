<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use PhpParser\Node\Expr;
use ReflectionClass;

/**
 * The variable a method body reaches the validated request through — a FormRequest's own `$this`, or the
 * request parameter of a laravel-actions hook or a controller action — beside the class `$this` names there.
 * {@see CopiedInputs} and {@see InputWritesScan} read every body through one, so they read one grammar.
 */
final readonly class InputHandle
{
    /**
     * @param  ReflectionClass<object>  $request  the request's class, whose methods a call on the handle runs
     * @param  ReflectionClass<object>  $owner  what `$this`, `self::`, `static::` and `parent::` name in the body
     */
    public function __construct(
        public string $variable,
        public ReflectionClass $request,
        public ReflectionClass $owner,
    ) {}

    /**
     * A FormRequest's own hook, where `$this` is the request.
     *
     * @param  ReflectionClass<object>  $class
     */
    public static function self(ReflectionClass $class): self
    {
        return new self('this', $class, $class);
    }

    /** Whether `$this` is the request itself rather than the object whose method this is. */
    public function isSelf(): bool
    {
        return $this->variable === 'this';
    }

    /** `$this`, whatever it names in the body. */
    public static function isThis(Expr $expr): bool
    {
        return $expr instanceof Expr\Variable && $expr->name === 'this';
    }

    /** The request itself. */
    public function is(Expr $expr): bool
    {
        return $expr instanceof Expr\Variable && $expr->name === $this->variable;
    }

    /** An expression on the request or on something reached from it — a bag, `json()`. */
    public function rootsAt(Expr $expr): bool
    {
        return $this->is(self::root($expr));
    }

    /** The variable, or other expression, a chain of fetches and calls starts from. */
    public static function root(Expr $expr): Expr
    {
        while ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch
            || $expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall || $expr instanceof Expr\ArrayDimFetch
        ) {
            $expr = $expr->var;
        }

        return $expr;
    }
}
