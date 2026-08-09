<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt\Return_;

/**
 * Reads a `rules()` method's returned array from its AST — the FormRequest/laravel-action analogue of
 * {@see InlineRulesVisitor}, sharing {@see RulesHarvestingVisitor}'s harvest. It only matches the returned
 * array literal, and never requests descent: the engine already visits every node of the traced method.
 */
final class RulesMethodVisitor extends RulesHarvestingVisitor
{
    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if ($node instanceof Return_ && $node->expr instanceof Array_) {
            $this->harvest($node->expr, $scope);
        }

        return false;
    }
}
