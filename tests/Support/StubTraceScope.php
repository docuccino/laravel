<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\DType\DType;
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
 * real). Non-constant sub-expressions (a variable) fold to `unknown`, exercising the degradation path.
 */
final class StubTraceScope implements TypeScope
{
    public function __construct(private readonly DType $receiverType) {}

    public function typeOf(Node\Expr $expr): DType
    {
        return $this->receiverType;
    }

    public function constantValueOf(Node\Expr $expr): ?ConstValue
    {
        return $this->fold($expr);
    }

    public function location(Node $node): SourceLocation
    {
        return new SourceLocation('test.php', $node->getStartLine(), $node->getStartFilePos());
    }

    private function fold(Node\Expr $expr): ?ConstValue
    {
        if ($expr instanceof Node\Expr\Array_) {
            $items = [];
            foreach ($expr->items as $item) {
                $items[] = $this->fold($item->value) ?? ConstValue::unknown('non-constant array item');
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
            $args = [];
            foreach ($expr->getArgs() as $arg) {
                $args[] = $this->fold($arg->value) ?? ConstValue::unknown('non-constant arg');
            }

            return ConstValue::descriptor($factory, $args);
        }

        // `new Iban('GB')` folds to an instance value, as the real engine does — the rules recovery keys
        // on the class to read its `#[RuleSchema]`.
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            $args = [];
            foreach ($expr->getArgs() as $arg) {
                $args[] = $this->fold($arg->value) ?? ConstValue::unknown('non-constant arg');
            }

            return ConstValue::instance(ltrim($expr->class->toString(), '\\'), $args);
        }

        return null;
    }
}
