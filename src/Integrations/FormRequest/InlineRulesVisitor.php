<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;

/**
 * Recovers the rules array from an inline `$request->validate([...])` / `Validator::make($data, [...])` in
 * the action body: field keys straight from the AST, each rule value constant-folded so `Rule::enum(…)`
 * descriptors survive. The shared harvest and unrecoverable bookkeeping live in
 * {@see RulesHarvestingVisitor}; this only locates the rules-array argument.
 *
 * It asks for descent into called project code, so a `Validator::make(…)` built inside a service or
 * Queries class a hop or two from the action is still reached; the engine declines vendor, magic and
 * over-budget callees itself.
 */
final class InlineRulesVisitor extends RulesHarvestingVisitor
{
    public function enterNode(Node $node, TypeScope $scope): bool
    {
        $rulesArgument = $this->rulesArgument($node);
        if ($rulesArgument instanceof Array_) {
            $this->harvest($rulesArgument, $scope);
        }

        return $node instanceof MethodCall || $node instanceof StaticCall;
    }

    /** The rules-array argument of a `validate()` / `Validator::make()` call, or null. */
    private function rulesArgument(Node $node): ?Node
    {
        if ($node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toString() === 'validate') {
            return $node->getArgs()[0]->value ?? null;
        }

        if ($node instanceof StaticCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'make'
            && $this->isValidatorFactory($node)
        ) {
            // Validator::make($data, $rules, ...) — the rules are the second argument.
            return $node->getArgs()[1]->value ?? null;
        }

        return null;
    }

    private function isValidatorFactory(StaticCall $node): bool
    {
        if (! $node->class instanceof Node\Name) {
            return false;
        }

        return $node->class->getLast() === 'Validator';
    }
}
