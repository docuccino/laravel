<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\QueryBuilder;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Recovers the single column a callback closure or custom-filter `__invoke` filters on, for two shapes
 * only: `$query->where(COLUMN, $value)` and `$query->where(COLUMN, OPERATOR, $value)`. `COLUMN` must be a
 * string literal, and `$query`/`$value` the callable's first two parameters — that's what proves the
 * value reaching the `where` IS the filter value and not something captured.
 *
 * Anything more (several statements, `orWhere`/`whereIn`, a nested closure, a computed column) returns
 * null and the filter stays a plain string. Pure AST, no engine and no reflection, so it recovers the
 * same literal in-process and inside the real-engine fixture trace.
 */
final class WhereColumnAnalyzer
{
    /** The column a callback closure or arrow function filters on. */
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

    /** The column a custom filter's `__invoke(Builder $query, $value, …)` filters on. */
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
     * The one statement must be `$query->where(…)`, bare or returned. More than one bails.
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

    /** COLUMN from either accepted `where` shape, else null. */
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

        // `where(col, $value)` — the filter value goes straight in.
        if (count($args) === 2 && self::isVariable($args[1] ?? null, $valueVar)) {
            return $column;
        }

        // `where(col, OPERATOR, $value)` — literal operator, then the filter value.
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
