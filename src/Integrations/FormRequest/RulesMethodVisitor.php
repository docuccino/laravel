<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt\Return_;

/**
 * A rules-array recoverer that reads a `rules()` method's returned array from its AST — the
 * FormRequest / laravel-action analogue of {@see InlineRulesVisitor}. The shared harvest (constant-
 * folding each field value so `Rule::enum(...)`/`Rule::in(...)` descriptors survive PHPStan's collapse
 * to a bare object, and recording unrecoverable fields) lives in {@see RulesHarvestingVisitor}; this
 * subclass supplies only the front matching — the returned array literal. It never requests descent;
 * the engine already visits every node of the traced method.
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
