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
 * A rules-array recoverer that reads an inline `$request->validate([...])` /
 * `Validator::make($data, [...])` call from the action body — field keys straight from the AST, each
 * rule value constant-folded so `Rule::enum(...)` descriptors survive; nothing is ever executed. The
 * shared harvest + unrecoverable-field bookkeeping lives in {@see RulesHarvestingVisitor}; this
 * subclass supplies only the front matching — locating the rules-array argument. It requests descent
 * into called project code so a `Validator::make(...)` built inside a Queries/service class one or
 * more hops from the action is reached (the modular GET-params pattern); the engine declines vendor /
 * magic / over-budget descent on its own (Spike B split).
 */
final class InlineRulesVisitor extends RulesHarvestingVisitor
{
    public function enterNode(Node $node, TypeScope $scope): bool
    {
        $rulesArgument = $this->rulesArgument($node);
        if ($rulesArgument instanceof Array_) {
            $this->harvest($rulesArgument, $scope);
        }

        // Descend into any app-code call so a validate() built inside a helper/Queries class is
        // reached; the engine bounds the descent and declines vendor/magic callees.
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
