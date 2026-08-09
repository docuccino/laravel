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
 * The shared back half of the two rules-array recoverers ({@see RulesMethodVisitor} for a `rules()`
 * override, {@see InlineRulesVisitor} for an inline `validate()` / `Validator::make()` call): the
 * folded-field accumulator, the unrecoverable-field list, and the {@see harvest()} that reads a
 * `['field' => <rule>]` array by constant-folding each value ({@see TypeScope::constantValueOf()} →
 * {@see ConstValueToRules}) so `Rule::enum(...)`/`Rule::in(...)` descriptors survive PHPStan's
 * collapse to a bare object. Nothing is executed. A field present in the array whose value folds to
 * no rules (a closure, a `new` rule object, `Rule::when(...)`, an unresolvable expression) is recorded
 * as unrecoverable so the caller can diagnose it rather than let it vanish silently. Subclasses supply
 * only the front matching — which AST node carries the rules array — via {@see enterNode()}.
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

    public function __construct(
        private readonly ConstValueToRules $folder = new ConstValueToRules,
    ) {}

    public function ruleSet(): RuleSet
    {
        return new RuleSet($this->fields);
    }

    /**
     * Field names present in the rules array whose value folded to no rules (never recovered).
     *
     * @return list<string>
     */
    public function unrecoverableFields(): array
    {
        return $this->unrecoverable;
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

                continue;
            }

            if (! in_array($field, $this->unrecoverable, true)) {
                $this->unrecoverable[] = $field;
            }
        }
    }
}
