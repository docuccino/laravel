<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Builds a scripted trace closure (for {@see StubTypeEngine}) that drives a real Query-Builder
 * visitor over a parsed QB chain snippet with a {@see StubTraceScope} — deterministically standing in
 * for the real engine so the workbench golden exercises the Query Builder integration end-to-end.
 */
final class QbTraceScript
{
    /**
     * @return callable(TraceVisitor): void
     */
    public static function forChain(string $chain, string $builderFqcn = 'Spatie\\QueryBuilder\\QueryBuilder'): callable
    {
        return static function (TraceVisitor $visitor) use ($chain, $builderFqcn): void {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse("<?php\n\$q = ".$chain.";\n") ?? [];
            $scope = new StubTraceScope(new ClassT($builderFqcn));

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
        };
    }
}
