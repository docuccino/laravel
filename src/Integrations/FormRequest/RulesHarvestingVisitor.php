<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;

/**
 * The shared back half of the two rules-array recoverers — {@see RulesMethodVisitor} for a `rules()`
 * override, {@see InlineRulesVisitor} for an inline `validate()`/`Validator::make()`. It holds the folded
 * fields, the unrecoverable list, and the {@see harvest()} that reads a `['field' => <rule>]` array by
 * constant-folding each value so `Rule::enum(…)`/`Rule::in(…)` descriptors survive PHPStan's collapse to a
 * bare object. Nothing is executed. Subclasses supply only the front matching — which AST node carries the
 * array — via {@see enterNode()}.
 *
 * A field whose value folds to no rules (a closure, an unannotated `new` rule object, `Rule::when(…)`, an
 * unresolvable expression) is recorded as unrecoverable so the caller can diagnose it instead of losing
 * it. A field that folds to SOME of its rules, having widened past values it could not read, is recorded
 * separately as widened — both are things the caller reports, and they are not the same loss.
 */
abstract class RulesHarvestingVisitor implements TraceVisitor
{
    /**
     * @var array<string, list<ValidationRule>>
     */
    private array $fields = [];

    /**
     * @var list<string>
     */
    private array $unrecoverable = [];

    /**
     * @var list<string>
     */
    private array $widened = [];

    public function __construct(
        private readonly ConstValueToRules $folder = new ConstValueToRules,
    ) {}

    public function ruleSet(): RuleSet
    {
        return new RuleSet($this->fields);
    }

    /**
     * Fields present in the rules array whose value folded to nothing.
     *
     * @return list<string>
     */
    public function unrecoverableFields(): array
    {
        return $this->unrecoverable;
    }

    /**
     * Fields that DID recover rules, having lost one the fold could see was there — values the rule states
     * without writing them at it. What they publish is true and says less than the code does, which is the
     * one degradation nothing else reports.
     *
     * @return list<string>
     */
    public function widenedFields(): array
    {
        return $this->widened;
    }

    /**
     * Files the fold read beyond the traced method — the annotated rule classes.
     *
     * @return list<string>
     */
    public function dependencyFiles(): array
    {
        return $this->folder->dependencyFiles();
    }

    protected function harvest(Array_ $array, TypeScope $scope): void
    {
        foreach ($array->items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            $field = $item->key->value;
            $value = $scope->constantValueOf($item->value);
            $rules = $value === null ? [] : $this->folder->fold($value);

            if ($rules !== []) {
                $this->fields[$field] = $rules;
                if ($this->folder->widened() && ! in_array($field, $this->widened, true)) {
                    $this->widened[] = $field;
                }

                continue;
            }

            if (! in_array($field, $this->unrecoverable, true)) {
                $this->unrecoverable[] = $field;
            }
        }
    }
}
