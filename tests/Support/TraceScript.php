<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\TraceVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Builds a scripted trace closure (for {@see StubTypeEngine}) that drives a real {@see TraceVisitor} over
 * a parsed code snippet with a {@see StubTraceScope} — deterministically standing in for the real engine,
 * so a trace-driven integration (the Query Builder's chain, a response factory's `download()`) is
 * exercised end-to-end without PHPStan.
 */
final class TraceScript
{
    /**
     * @param  string  $chain  the expression the walk is over, as source
     * @param  string  $builderFqcn  the type {@see StubTraceScope} answers for every receiver in it
     * @param  string  $file  the file the snippet stands for — one document may script SEVERAL walks (an
     *                        action, then each injected builder's constructor), and two of them claiming
     *                        one file would put two different call sites at the same place
     * @param  array<string, array{0: ?ConstValue, 1: ?Node\Expr}>  $foldedReturns  method name → the
     *                                                                              answer a deferred return fold gives, drained after the
     *                                                                              walk exactly as the engine drains before the trace returns
     * @param  array<string, DType>  $variableTypes  variable name → its type, for a snippet where one
     *                                               receiver is not the chain's (a `$request` the page size
     *                                               is read off)
     * @return callable(TraceVisitor): void
     */
    public static function forChain(
        string $chain,
        string $builderFqcn = 'Spatie\\QueryBuilder\\QueryBuilder',
        string $file = 'test.php',
        array $foldedReturns = [],
        array $variableTypes = [],
    ): callable {
        return static function (TraceVisitor $visitor) use ($chain, $builderFqcn, $file, $foldedReturns, $variableTypes): void {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse("<?php\n\$q = ".$chain.";\n") ?? [];
            $scope = new StubTraceScope(new ClassT($builderFqcn), $foldedReturns, file: $file, variableTypes: $variableTypes);

            $traverser = new NodeTraverser(new class($visitor, $scope) extends NodeVisitorAbstract
            {
                public function __construct(
                    private readonly TraceVisitor $visitor,
                    private readonly StubTraceScope $scope,
                ) {}

                public function enterNode(Node $node): ?int
                {
                    if ($node instanceof Node\Expr) {
                        $this->visitor->enterNode($node, $this->scope);
                    }

                    return null;
                }
            });
            $traverser->traverse($ast);
            // The engine answers deferred return folds once the walk is over; so does the stub.
            $scope->drainReturnFolds();
        };
    }

    /**
     * What the engine hands back for one folded return: the folded value plus the returned expression
     * itself (AST-only, since it belongs to the callee's file). Folded through the same stub scope the
     * visitor sees, so a fixture reads like the real answer.
     *
     * @return array{0: ?ConstValue, 1: ?Node\Expr}
     */
    public static function foldOf(string $expression): array
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$expression.';') ?? [];
        $statement = $ast[0] ?? null;
        $expr = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;

        return $expr === null
            ? [null, null]
            : [(new StubTraceScope(new ClassT('Spatie\\QueryBuilder\\QueryBuilder')))->constantValueOf($expr), $expr];
    }
}
