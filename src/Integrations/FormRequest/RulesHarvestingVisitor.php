<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\ConstValue;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
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
 * it. A field that folds to SOME of its rules is recorded with what it left out: values it could not read
 * (widened), or whole rules that can only refuse values (unread). A field with a rule whose content is
 * unknown is given up and recorded as opaque: it is published with no rules at all, and no other recovery
 * may restate the ones it had ({@see ConstValueToRules}).
 */
abstract class RulesHarvestingVisitor implements TraceVisitor
{
    /**
     * What the author does about each of the two losses. They live here because this class is what
     * decides a field is one or the other, and both recoverers report the same sentence — one owner, so
     * the wording cannot drift from the rule it explains.
     */
    public const string UNRECOVERABLE_HELP = 'Its rules are a closure, a custom Rule object with no #[RuleSchema], or a Rule::when()/conditional descriptor. Express the field with recoverable rules (string/array rules, Rule::enum(), Rule::in(), …), or annotate the rule class with #[RuleSchema], so it is documented.';

    public const string UNREAD_HELP = 'The rule is a closure, a custom Rule object with no #[RuleSchema], or a framework rule this build does not read, and it can only refuse values, so the field is documented without it: wider than the endpoint, and true. Annotate the rule class with #[RuleSchema] to document what it accepts (an empty #[RuleSchema] says it states nothing a schema can), or write the rule as one the build reads (string rules, Rule::enum(), Rule::in(), …).';

    public const string OPAQUE_HELP = 'A rule whose content is unknown (Rule::when(), Rule::forEach(), a spread, or a value from a call or a variable) can make the field optional, nullable or excluded, so none of the rules beside it are true on their own and the field is published with no constraints. Write the rules out where they apply, or declare the field with #[BodyParameter] or #[QueryParameter].';

    public const string WIDENED_HELP = 'A value the rule states is not written at the rule — it comes from a call, a variable or a spread — and a partial list would make a client reject a value the API accepts. Write every value where the rule is, which is what settles it; an overlay corrects the document instead, and this notice keeps naming the rule.';

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

    /**
     * @var array<string, list<string>>
     */
    private array $unread = [];

    /**
     * @var array<string, string>
     */
    private array $opaque = [];

    public function __construct(
        private readonly ConstValueToRules $folder = new ConstValueToRules,
    ) {}

    /**
     * The recovered fields. A field given up ({@see opaqueFields()}) stays, with no rules: its name is
     * known, and an unconstrained, optional field is true whatever the unread rule turns out to be.
     */
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
     * Fields that recovered rules and left out others that can only refuse values, by field: the names of
     * the rules left out.
     *
     * @return array<string, list<string>>
     */
    public function unreadRules(): array
    {
        return array_diff_key($this->unread, $this->opaque);
    }

    /**
     * Fields given up because one of their rules has unknown content, by field: the name of that rule.
     * What the rest of the field's rules say is not true on its own, so no other reading may publish it.
     *
     * @return array<string, string>
     */
    public function opaqueFields(): array
    {
        return $this->opaque;
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
            $rules = $value === null ? [] : $this->folder->fold(self::withClosures($value, $item->value));

            $opaque = $value === null ? null : $this->folder->opaque();
            if ($opaque !== null || isset($this->opaque[$field])) {
                $this->opaque[$field] ??= (string) $opaque;
                $this->fields[$field] = [];

                continue;
            }

            if ($rules !== []) {
                $this->fields[$field] = $rules;
                if ($this->folder->widened() && ! in_array($field, $this->widened, true)) {
                    $this->widened[] = $field;
                }

                if ($this->folder->unread() !== []) {
                    $this->unread[$field] = array_values(array_unique([...$this->unread[$field] ?? [], ...$this->folder->unread()]));
                }

                continue;
            }

            if (! in_array($field, $this->unrecoverable, true)) {
                $this->unrecoverable[] = $field;
            }
        }
    }

    /**
     * A closure in a rule list folds to nothing, like any value the fold cannot read, but it is a rule
     * object — Laravel runs it as a check on the value — so it stands in as an instance of `Closure`.
     */
    private static function withClosures(ConstValue $value, Expr $node): ConstValue
    {
        if (! $value->isArray() || ! $node instanceof Array_ || count($node->items) !== count($value->items)) {
            return $value;
        }

        $items = [];
        foreach ($value->items as $index => $folded) {
            $written = $node->items[$index]->value;
            $items[] = $written instanceof Closure || $written instanceof ArrowFunction
                ? ConstValue::instance('Closure', [])
                : $folded;
        }

        return ConstValue::array($items);
    }
}
