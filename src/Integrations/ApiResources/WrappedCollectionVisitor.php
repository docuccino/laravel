<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\ApiResources;

use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;

/**
 * Finds what the walk hands each construction of one collection class — `Resource::collection($x)`,
 * `$x->toResourceCollection(…)`, `new Collection($x)`, `Collection::make($x)` — and whether every one of
 * them is a plain list: an array, the base collection, or an Eloquent one, each of which Laravel's
 * `collectResource()` turns into the base collection it leaves in `$this->resource`
 * ({@see WrappedResource::PLAIN}). Anything else, a paginator included, proves nothing.
 *
 * A construction the walk cannot recognise — a macro, a collection built in vendor code — is not seen,
 * so the answer is only as good as the constructions it does see; one it sees that is not a plain list
 * withholds it.
 */
final class WrappedCollectionVisitor implements TraceVisitor
{
    private const ELOQUENT_COLLECTION = 'Illuminate\\Database\\Eloquent\\Collection';

    private int $plain = 0;

    private int $other = 0;

    /**
     * @param  string  $collection  the class whose constructions are read
     */
    public function __construct(private readonly string $collection) {}

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        $wrapped = self::wrapped($node);
        if ($wrapped !== null && $this->builds($node, $scope)) {
            self::isPlain($scope->typeOf($wrapped)) ? $this->plain++ : $this->other++;
        }

        // Descend into any app-code call, so a collection a helper builds is seen too.
        return $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall;
    }

    /** Whether the walk saw the collection built, and every time around a plain list. */
    public function wrapsPlainList(): bool
    {
        return $this->plain > 0 && $this->other === 0;
    }

    /** What a construction hands the collection to wrap, or null where `$node` builds none. */
    private static function wrapped(Node $node): ?Node\Expr
    {
        if ($node instanceof Node\Expr\MethodCall) {
            return $node->name instanceof Node\Identifier && $node->name->toString() === 'toResourceCollection' ? $node->var : null;
        }

        $builds = ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name)
            || ($node instanceof Node\Expr\StaticCall
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toString(), ['collection', 'make'], true));
        if (! $builds) {
            return null;
        }

        // The resource is the first argument: positional, or by the name every constructor here gives it.
        foreach ($node->getArgs() as $position => $arg) {
            if ($arg->unpack) {
                return null;
            }
            if ($arg->name === null ? $position === 0 : $arg->name->toString() === 'resource') {
                return $arg->value;
            }
        }

        return null;
    }

    /** Whether `$node` evaluates to the collection this walk is reading — by its type, not by its spelling. */
    private function builds(Node $node, TypeScope $scope): bool
    {
        if (! $node instanceof Node\Expr) {
            return false;
        }

        $type = $scope->typeOf($node);

        return $type instanceof ClassT && $type->fqcn === $this->collection;
    }

    private static function isPlain(DType $type): bool
    {
        // An `(object)` cast is a shape too, and no list.
        if (($type instanceof ArrayShapeT && ! $type->isObject) || $type instanceof ListT || $type instanceof MapT) {
            return true;
        }

        return $type instanceof ClassT
            && ($type->fqcn === WrappedResource::PLAIN || is_a($type->fqcn, self::ELOQUENT_COLLECTION, true));
    }
}
