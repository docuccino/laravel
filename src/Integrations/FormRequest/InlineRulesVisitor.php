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
    /**
     * Each call rules were harvested from, by where it is written, so one the walk reaches twice counts once.
     *
     * @var array<string, true>
     */
    private array $sites = [];

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        $rulesArgument = self::rulesArgumentOf($node);
        if ($rulesArgument instanceof Array_) {
            $location = $scope->location($node);
            $this->sites[$location->file.':'.$location->line.':'.$location->pos] = true;
            $this->harvest($rulesArgument, $scope);
        }

        return $node instanceof MethodCall || $node instanceof StaticCall;
    }

    /** How many calls in the walk the rules were harvested from. */
    public function validations(): int
    {
        return count($this->sites);
    }

    /** The rules-array argument of a `validate()` / `Validator::make()` call, or null. */
    public static function rulesArgumentOf(Node $node): ?Node
    {
        if ($node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toString() === 'validate') {
            return $node->getArgs()[0]->value ?? null;
        }

        if ($node instanceof StaticCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'make'
            && $node->class instanceof Node\Name
            && $node->class->getLast() === 'Validator'
        ) {
            // Validator::make($data, $rules, ...) — the rules are the second argument.
            return $node->getArgs()[1]->value ?? null;
        }

        return null;
    }
}
