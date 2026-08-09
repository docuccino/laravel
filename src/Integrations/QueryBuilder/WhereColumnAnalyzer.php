<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Recovers the single column a Query-Builder callback closure or custom-filter `__invoke` body
 * filters on, for the simple shapes the design admits (§Filter-kind inference):
 *
 *   - `$query->where(COLUMN, $value)`                — an equality on a literal column;
 *   - `$query->where(COLUMN, OPERATOR, $value)`      — the same with an explicit literal operator.
 *
 * `COLUMN` is a string literal; `$query`/`$value` must be the callable's first/second parameters (so
 * the value flowing into the `where` is the filter value, not some captured variable). ANYTHING more
 * complex — multiple statements, `orWhere`/`whereIn`, a nested closure, a conditional, a non-literal
 * column, a value that is not the second parameter — returns null (the filter degrades to a plain
 * string, silently, per the design). Pure AST: no type engine, no reflection, so it recovers the same
 * literal both in-process and inside the real-engine fixture trace.
 */
final class WhereColumnAnalyzer
{
    /** The recovered column of a callback closure / arrow function, or null when it does not match. */
    public function fromClosure(Closure|ArrowFunction $fn): ?string
    {
        $params = $fn->params;
        $queryVar = self::paramName($params[0] ?? null);
        $valueVar = self::paramName($params[1] ?? null);
        if ($queryVar === null || $valueVar === null) {
            return null;
        }

        if ($fn instanceof ArrowFunction) {
            return $this->fromWhereCall($fn->expr, $queryVar, $valueVar);
        }

        return $this->fromStatements($fn->stmts, $queryVar, $valueVar);
    }

    /** The recovered column of a custom filter's `__invoke(Builder $query, $value, …)` method. */
    public function fromInvoke(ClassMethod $method): ?string
    {
        $params = $method->params;
        $queryVar = self::paramName($params[0] ?? null);
        $valueVar = self::paramName($params[1] ?? null);
        if ($queryVar === null || $valueVar === null || $method->stmts === null) {
            return null;
        }

        return $this->fromStatements($method->stmts, $queryVar, $valueVar);
    }

    /**
     * The single statement must be a bare `$query->where(…)` expression (or `return $query->where(…)`);
     * more than one statement is "too complex" and bails.
     *
     * @param  array<array-key, Node\Stmt>  $stmts
     */
    private function fromStatements(array $stmts, string $queryVar, string $valueVar): ?string
    {
        $stmts = array_values($stmts);
        if (count($stmts) !== 1) {
            return null;
        }

        $stmt = $stmts[0];
        $expr = match (true) {
            $stmt instanceof Node\Stmt\Expression => $stmt->expr,
            $stmt instanceof Node\Stmt\Return_ => $stmt->expr,
            default => null,
        };

        return $expr === null ? null : $this->fromWhereCall($expr, $queryVar, $valueVar);
    }

    /**
     * Match `$query->where(COLUMN, $value)` / `$query->where(COLUMN, OPERATOR, $value)` and return
     * COLUMN, or null for any other expression.
     */
    private function fromWhereCall(Node\Expr $expr, string $queryVar, string $valueVar): ?string
    {
        if (! $expr instanceof Node\Expr\MethodCall
            || ! $expr->var instanceof Node\Expr\Variable
            || $expr->var->name !== $queryVar
            || ! $expr->name instanceof Node\Identifier
            || $expr->name->toString() !== 'where'
        ) {
            return null;
        }

        $args = $expr->getArgs();
        $column = self::stringArg($args[0] ?? null);
        if ($column === null) {
            return null;
        }

        // `where(col, $value)` — the value is the filter value directly.
        if (count($args) === 2 && self::isVariable($args[1] ?? null, $valueVar)) {
            return $column;
        }

        // `where(col, OPERATOR, $value)` — a literal operator, then the filter value.
        if (count($args) === 3 && self::stringArg($args[1] ?? null) !== null && self::isVariable($args[2] ?? null, $valueVar)) {
            return $column;
        }

        return null;
    }

    private static function paramName(?Node\Param $param): ?string
    {
        return $param !== null && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
            ? $param->var->name
            : null;
    }

    private static function stringArg(?Node\Arg $arg): ?string
    {
        if ($arg?->value instanceof Node\Scalar\String_ && $arg->value->value !== '') {
            return $arg->value->value;
        }

        return null;
    }

    private static function isVariable(?Node\Arg $arg, string $name): bool
    {
        return $arg?->value instanceof Node\Expr\Variable && $arg->value->name === $name;
    }
}
