<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Eloquent;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

/**
 * Reads a model's own `resolveRouteBinding()`, `resolveSoftDeletableRouteBinding()` or
 * `resolveRouteBindingQuery()` for the one shape that looks a segment up exactly as the framework does:
 * a single query on the model itself carrying `where($field ?? $this->getRouteKeyName(), $value)`, the
 * value as it arrived. Further `where()`s only narrow which records resolve, so they leave the column
 * alone; anything that could match some other way — an `or` clause, the value changed or read twice, a
 * column chosen in code, a query on another model — is not this shape, and the binding stays unread.
 */
final class RouteBindingOverride
{
    /** Each method read, and the position of its value, column and query parameters. */
    private const PARAMETERS = [
        'resolveroutebinding' => [0, 1, null],
        'resolvesoftdeletableroutebinding' => [0, 1, null],
        'resolveroutebindingquery' => [1, 2, 0],
    ];

    /** The calls a query on the model itself starts from, on `self::`, `static::` or `$this`. */
    private const ROOTS = ['query', 'newquery', 'newmodelquery', 'where'];

    /**
     * Calls that write a condition onto the query outside any group, so a record may resolve without
     * matching the column at all. Every `or…` method is one too ({@see joinsWithOr()}).
     */
    public const UNGROUPED = ['when', 'unless', 'whereraw'];

    /** The column is `$field ?? $this->getRouteKeyName()`: the route's, else the route key. */
    private const ROUTE_FIELD_OR_KEY = 1;

    /** The column is `$this->getRouteKeyName()`, whatever the route names. */
    private const ROUTE_KEY = 2;

    /** The column is `$field`, which only a route naming a column fills. */
    private const ROUTE_FIELD = 3;

    /** Whether `$method` is one this reads. */
    public static function reads(string $method): bool
    {
        return isset(self::PARAMETERS[strtolower($method)]);
    }

    /**
     * Whether `$method` looks up a segment naming `$field` (null for `{model}`) on the column the
     * framework's own lookup would. `$selfIsBound` is whether `self::` in it is the bound model, which it
     * is not where a parent class declares it.
     */
    public static function matchesLikeTheFramework(ClassMethod $method, ?string $field, bool $selfIsBound): bool
    {
        $positions = self::PARAMETERS[$method->name->toLowerString()] ?? null;
        if ($positions === null) {
            return false;
        }

        [$value, $column, $query] = [
            self::parameter($method, $positions[0]),
            self::parameter($method, $positions[1]),
            $positions[2] === null ? null : self::parameter($method, $positions[2]),
        ];

        // The lookup is the method's first statement, so nothing runs ahead of it.
        $return = ($method->stmts ?? [])[0] ?? null;
        if ($value === null || $column === null || ($positions[2] !== null && $query === null)
            || ! $return instanceof Return_ || $return->expr === null) {
            return false;
        }

        // Read once, where it is matched: any other read could change it before the match runs.
        $calls = self::chain($return->expr, $query, $selfIsBound);
        if ($calls === null || count(self::variables($method, $value)) !== 1) {
            return false;
        }

        $form = null;
        foreach ($calls as $call) {
            $name = $call->name instanceof Identifier ? $call->name->toLowerString() : null;
            if ($name === null || $call->isFirstClassCallable() || self::joinsWithOr($name) || in_array($name, self::UNGROUPED, true)) {
                return false;
            }

            $matched = $name === 'where' ? self::matchedColumn($call, $value) : null;
            if ($matched !== null) {
                // Every read of the column parameter is the one this matches with, so no branch picks another.
                if (count(self::variables($method, $column)) !== count(self::variables($matched, $column))) {
                    return false;
                }

                $form = self::form($matched, $column);
            }
        }

        return match ($form) {
            self::ROUTE_FIELD_OR_KEY => true,
            self::ROUTE_KEY => $field === null,
            self::ROUTE_FIELD => $field !== null,
            default => false,
        };
    }

    /**
     * Whether a builder method is the `or` form of another, which joins its condition to everything before
     * it with OR rather than AND. `order…` is ordering, not that.
     */
    public static function joinsWithOr(string $method): bool
    {
        $method = strtolower($method);

        return str_starts_with($method, 'or') && ! str_starts_with($method, 'order');
    }

    /**
     * The calls building a query on the model itself, innermost first, or null when the query starts
     * anywhere else — another model, a relation, a helper.
     *
     * @return list<MethodCall|StaticCall>|null
     */
    private static function chain(Expr $expr, ?string $query, bool $selfIsBound): ?array
    {
        $calls = [];
        while ($expr instanceof MethodCall) {
            array_unshift($calls, $expr);
            $expr = $expr->var;
        }

        if ($query !== null && self::isVariable($expr, $query)) {
            return $calls;
        }

        if ($expr instanceof StaticCall && $expr->class instanceof Name) {
            $class = $expr->class->toLowerString();
            if ($class !== 'static' && ($class !== 'self' || ! $selfIsBound)) {
                return null;
            }

            array_unshift($calls, $expr);
        } elseif (! self::isVariable($expr, 'this')) {
            return null;
        }

        $root = $calls[0] ?? null;

        return $root !== null && $root->name instanceof Identifier && in_array($root->name->toLowerString(), self::ROOTS, true)
            ? $calls
            : null;
    }

    /** The column a `where(column, $value)` or `where(column, '=', $value)` matches `$value` on. */
    private static function matchedColumn(MethodCall|StaticCall $call, string $value): ?Expr
    {
        $args = $call->getArgs();
        foreach ($args as $arg) {
            if ($arg->unpack || $arg->byRef || $arg->name !== null) {
                return null;
            }
        }

        $operator = count($args) === 3 ? $args[1]->value : null;
        $last = $args[count($args) - 1] ?? null;
        if ((count($args) !== 2 && ! ($operator instanceof String_ && $operator->value === '=')) || $last === null || ! self::isVariable($last->value, $value)) {
            return null;
        }

        return $args[0]->value;
    }

    /** Which of the framework's columns `$column` is, or null when it is one of its own. */
    private static function form(Expr $column, string $field): ?int
    {
        return match (true) {
            self::isVariable($column, $field) => self::ROUTE_FIELD,
            self::isRouteKeyName($column) => self::ROUTE_KEY,
            $column instanceof Coalesce => self::isVariable($column->left, $field) && self::isRouteKeyName($column->right) ? self::ROUTE_FIELD_OR_KEY : null,
            $column instanceof Ternary => $column->if === null && self::isVariable($column->cond, $field) && self::isRouteKeyName($column->else) ? self::ROUTE_FIELD_OR_KEY : null,
            default => null,
        };
    }

    private static function isRouteKeyName(Expr $expr): bool
    {
        return $expr instanceof MethodCall
            && self::isVariable($expr->var, 'this')
            && $expr->name instanceof Identifier
            && $expr->name->toLowerString() === 'getroutekeyname'
            && $expr->args === [];
    }

    private static function isVariable(Expr $expr, string $name): bool
    {
        return $expr instanceof Variable && $expr->name === $name;
    }

    /** The name of the method's parameter at `$position`, or null where it has none. */
    private static function parameter(ClassMethod $method, int $position): ?string
    {
        $variable = ($method->params[$position] ?? null)?->var;

        return $variable instanceof Variable && is_string($variable->name) ? $variable->name : null;
    }

    /**
     * Every read of `$name` under the node, closures and arrow functions included.
     *
     * @return list<Variable>
     */
    private static function variables(ClassMethod|Expr $node, string $name): array
    {
        /** @var list<Variable> $found */
        $found = (new NodeFinder)->find(
            $node instanceof ClassMethod ? ($node->stmts ?? []) : [$node],
            static fn (object $candidate): bool => $candidate instanceof Variable && $candidate->name === $name,
        );

        return $found;
    }
}
