<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\TraceVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Builds a scripted trace closure (for {@see StubTypeEngine}) that drives a real rules visitor over a
 * parsed snippet with a {@see StubTraceScope} — a `return [...]` body for the `rules()` visitor, a
 * `$request->validate([...])` statement for the inline one. The same recovery on the real engine is
 * proven in the fixture group.
 */
final class RulesTraceScript
{
    /**
     * @param  string  $php  statements, without the opening tag
     * @return callable(TraceVisitor): void
     */
    public static function forPhp(string $php): callable
    {
        return static function (TraceVisitor $visitor) use ($php): void {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse("<?php\n".$php."\n") ?? [];
            $scope = new StubTraceScope(new UnknownT('n/a'));

            $traverser = new NodeTraverser(new class($visitor, $scope) extends NodeVisitorAbstract
            {
                public function __construct(
                    private readonly TraceVisitor $visitor,
                    private readonly StubTraceScope $scope,
                ) {}

                public function enterNode(Node $node): ?int
                {
                    $this->visitor->enterNode($node, $this->scope);

                    return null;
                }
            });
            $traverser->traverse($ast);
        };
    }
}
