<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\TraceVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionClass;

/**
 * A scripted trace for a paginating chain whose SIZE comes from a helper: the action's snippet, then the
 * helper class's own source, the way the engine walks a call site and then descends into the callee. The
 * helper is walked under its real path, so the page-size recovery's reflection correlation is proven
 * against its real lines rather than a hand-built range.
 */
final class PageSizeTraceScript
{
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';

    private const REQUEST = 'Illuminate\\Http\\Request';

    /**
     * @param  string  $chain  the action's paginating chain, e.g. `$q->paginate(Helper::size($request))`
     * @param  list<class-string>  $descendInto  helper classes whose real source the walk continues into
     * @return callable(TraceVisitor): void
     */
    public static function forChain(string $chain, array $descendInto = [], string $file = 'test.php'): callable
    {
        return static function (TraceVisitor $visitor) use ($chain, $descendInto, $file): void {
            self::walk($visitor, "<?php\n\$q = ".$chain.";\n", $file);

            foreach ($descendInto as $class) {
                $helper = (new ReflectionClass($class))->getFileName();
                if ($helper !== false) {
                    self::walk($visitor, (string) file_get_contents($helper), $helper);
                }
            }
        };
    }

    private static function walk(TraceVisitor $visitor, string $code, string $file): void
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];

        // Everything but `$request` is the chain's own receiver, which is what makes the terminal a
        // terminal; the request is what makes a read a read.
        $scope = new StubTraceScope(
            new ClassT(self::BUILDER),
            file: $file,
            variableTypes: ['request' => new ClassT(self::REQUEST)],
        );

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
    }
}
