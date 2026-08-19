<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\FoldsCallReturns;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;

/**
 * An in-process {@see TypeScope} for driving a {@see TraceVisitor} over
 * real php-parser nodes without booting PHPStan: {@see typeOf()} returns a fixed receiver type (the
 * chain is known to be a query builder in the test snippet) and {@see constantValueOf()} folds the
 * AST directly — array literals per item, literals to scalars, factory static-calls to descriptors, `new`
 * expressions to instance
 * values (mirroring what the real engine hands back, so the visitor's harvest logic is exercised for
 * real). Non-constant sub-expressions (a variable) fold to `unknown`, exercising the degradation path, and
 * arguments are placed by the same {@see ArgumentSlots} the engine places them with.
 *
 * It also stands in for the engine's deferred return folds ({@see FoldsCallReturns}): `$foldedReturns` says
 * what a call to a given method name answers with, queued and then drained by {@see drainReturnFolds()} the
 * way the Tracer drains once a walk is over. A method the map doesn't mention is one the engine declines to
 * queue at all — a vendor or unresolvable callee.
 */
final class StubTraceScope implements FoldsCallReturns, TypeScope
{
    /** @var list<array{0: callable(?ConstValue, ?Node\Expr): void, 1: ?ConstValue, 2: ?Node\Expr}> */
    private array $pending = [];

    /**
     * @param  array<string, array{0: ?ConstValue, 1: ?Node\Expr}>  $foldedReturns  method name → the fold's answer
     * @param  string  $file  the file this snippet stands for — two scripted walks over DIFFERENT code must
     *                        not claim the same one, or its call sites collide
     * @param  array<string, DType>  $variableTypes  variable name → its type, for a snippet where not every
     *                                               receiver is the chain's (a `$request` beside a builder)
     */
    public function __construct(
        private readonly DType $receiverType,
        private readonly array $foldedReturns = [],
        private readonly string $file = 'test.php',
        private readonly array $variableTypes = [],
    ) {}

    public function deferReturnFold(Node\Expr $call, callable $onFolded): bool
    {
        $name = ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall)
            && $call->name instanceof Node\Identifier
                ? $call->name->toString()
                : null;

        if ($name === null || ! array_key_exists($name, $this->foldedReturns)) {
            return false;
        }

        $this->pending[] = [$onFolded, ...$this->foldedReturns[$name]];

        return true;
    }

    /** Answer every queued fold, in request order, as the engine does after the walk. */
    public function drainReturnFolds(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as [$onFolded, $value, $expr]) {
            $onFolded($value, $expr);
        }
    }

    public function typeOf(Node\Expr $expr): DType
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return $this->variableTypes[$expr->name] ?? $this->receiverType;
        }

        return $this->receiverType;
    }

    public function constantValueOf(Node\Expr $expr): ?ConstValue
    {
        return $this->fold($expr);
    }

    public function location(Node $node): SourceLocation
    {
        return new SourceLocation($this->file, $node->getStartLine(), $node->getStartFilePos());
    }

    private function fold(Node\Expr $expr): ?ConstValue
    {
        if ($expr instanceof Node\Expr\Array_) {
            $items = [];
            foreach ($expr->items as $item) {
                $items[] = $item->unpack
                    ? ConstValue::spread('spread array item')
                    : $this->fold($item->value) ?? ConstValue::unknown('non-constant array item');
            }

            return ConstValue::array($items);
        }

        if ($expr instanceof Node\Scalar\String_) {
            return ConstValue::scalar($expr->value);
        }

        if ($expr instanceof Node\Scalar\Int_) {
            return ConstValue::scalar($expr->value);
        }

        if ($expr instanceof Node\Expr\ConstFetch) {
            return match (strtolower($expr->name->toString())) {
                'true' => ConstValue::scalar(true),
                'false' => ConstValue::scalar(false),
                'null' => ConstValue::scalar(null),
                default => null,
            };
        }

        // `Model::class` folds to its class-name string, as PHPStan does for the real engine — the QB
        // subject-model recovery keys on it (the snippet must use the fully-qualified name, since the
        // stub has no namespace-resolution scope).
        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'class'
        ) {
            return ConstValue::scalar(ltrim($expr->class->toString(), '\\'));
        }

        // A non-`::class` class constant folds to the member NAME, mirroring the real engine's enum-case
        // handling (`FilterOperator::EQUAL` → `'EQUAL'`) — the QB operator filter keys on it. The stub's
        // controlled snippets only reference enum cases here, so this needs no enum_exists probe.
        if ($expr instanceof Node\Expr\ClassConstFetch && $expr->name instanceof Node\Identifier) {
            return ConstValue::scalar($expr->name->toString());
        }

        if ($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier) {
            $factory = $expr->class->toString().'::'.$expr->name->toString();

            return ConstValue::descriptor($factory, $this->foldArgs($expr->getArgs()));
        }

        // A fluent call over a descriptor receiver appends to its chain, as the real engine does, so
        // `AllowedFilter::partial('email')->nullable()` keeps both halves. A first-class callable carries no
        // args (and `getArgs()` asserts on one), so it declines.
        if ($expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && ! $expr->isFirstClassCallable()
        ) {
            $receiver = $this->fold($expr->var);
            if ($receiver !== null && $receiver->isDescriptor()) {
                return $receiver->withChainedCall($expr->name->toString(), $this->foldArgs($expr->getArgs()));
            }
        }

        // `new Iban('GB')` folds to an instance value, as the real engine does — the rules recovery keys
        // on the class to read its `#[RuleSchema]`.
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return ConstValue::instance(ltrim($expr->class->toString(), '\\'), $this->foldArgs($expr->getArgs()));
        }

        return null;
    }

    /**
     * The same placement the real fold uses ({@see ArgumentSlots}): a written-out spread expands into the
     * positions it takes, and anything holding no position of its own ends the list with a marker. A stub
     * that placed arguments differently from the engine would prove the readers work on shapes they never
     * see.
     *
     * @param  array<Node\Arg>  $args
     * @return list<ConstValue>
     */
    private function foldArgs(array $args): array
    {
        $slots = ArgumentSlots::of($args);

        $folded = [];
        foreach ($slots->positional() as $expr) {
            $folded[] = $this->fold($expr) ?? ConstValue::unknown('non-constant arg');
        }

        if (! $slots->isIndexable()) {
            $folded[] = ConstValue::spread('unplaceable arg');
        }

        return $folded;
    }
}
