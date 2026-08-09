<?php

declare(strict_types=1);

use Docuccino\Laravel\Integrations\QueryBuilder\WhereColumnAnalyzer;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * The shared column recogniser behind callback closures and custom-filter `__invoke` bodies: it
 * recovers the column of the two simple `where` shapes and BAILS (returns null → the filter stays a
 * plain string) on anything more complex. Pure AST, dataset-driven over both the matching and the
 * degradation shapes.
 */
function qbParseClosure(string $expr): Closure|ArrowFunction
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse("<?php \$f = {$expr};") ?? [];
    $fn = (new NodeFinder)->findFirst($ast, static fn (Node $n): bool => $n instanceof Closure || $n instanceof ArrowFunction);

    if (! $fn instanceof Closure && ! $fn instanceof ArrowFunction) {
        throw new RuntimeException('expected a closure or arrow function in the snippet');
    }

    return $fn;
}

function qbParseInvoke(string $body): ClassMethod
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse(
        "<?php class F { public function __invoke(\$query, \$value, \$property) { {$body} } }",
    ) ?? [];
    $method = (new NodeFinder)->findFirst($ast, static fn (Node $n): bool => $n instanceof ClassMethod);

    if (! $method instanceof ClassMethod) {
        throw new RuntimeException('expected a class method in the snippet');
    }

    return $method;
}

it('recovers the column of the simple where shapes from a callback closure', function (string $expr, ?string $column): void {
    expect((new WhereColumnAnalyzer)->fromClosure(qbParseClosure($expr)))->toBe($column);
})->with([
    'two-arg equality' => ['function ($q, $value) { $q->where(\'is_active\', $value); }', 'is_active'],
    'three-arg operator' => ['function ($q, $value) { $q->where(\'score\', \'>=\', $value); }', 'score'],
    'arrow two-arg' => ['fn ($q, $value) => $q->where(\'email\', $value)', 'email'],
    // Degradations — every one bails to null (plain string).
    'multiple statements' => ['function ($q, $value) { $q->where(\'a\', $value); $q->where(\'b\', $value); }', null],
    'orWhere' => ['function ($q, $value) { $q->orWhere(\'a\', $value); }', null],
    'whereIn' => ['function ($q, $value) { $q->whereIn(\'a\', $value); }', null],
    'value is not the second parameter' => ['function ($q, $value) { $q->where(\'a\', $other); }', null],
    'non-literal column' => ['function ($q, $value) { $q->where($col, $value); }', null],
    'conditional body' => ['function ($q, $value) { if ($value) { $q->where(\'a\', $value); } }', null],
    'receiver is not the query parameter' => ['function ($q, $value) { $other->where(\'a\', $value); }', null],
    'three-arg value not second parameter' => ['function ($q, $value) { $q->where(\'a\', \'=\', $other); }', null],
    'no value parameter' => ['function ($q) { $q->where(\'a\', 1); }', null],
]);

it('recovers the column from a custom filter __invoke body', function (string $body, ?string $column): void {
    expect((new WhereColumnAnalyzer)->fromInvoke(qbParseInvoke($body)))->toBe($column);
})->with([
    'single where' => ['$query->where(\'score\', $value);', 'score'],
    'return where' => ['return $query->where(\'score\', $value);', 'score'],
    'operator where' => ['$query->where(\'score\', \'>\', $value);', 'score'],
    'two wheres bails' => ['$query->where(\'a\', $value); $query->where(\'b\', $value);', null],
    'empty body bails' => ['', null],
]);
