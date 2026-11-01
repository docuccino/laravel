<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Laravel\Integrations\Support\DependencyFileSet;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\CustomRuleReader;
use Docuccino\Laravel\Integrations\Validation\RuleLosses;
use Docuccino\Laravel\Integrations\Validation\UnreadRule;

/**
 * Turns a `rules()` inferred return type — an {@see ArrayShapeT} of `field => rule` — into a {@see RuleSet}.
 * The engine reads the literal array as a constant array shape, so pipe-string and array-of-string rules are
 * recovered without instantiating the request or running `rules()`.
 *
 * By this stage a rule object is only its class, so it reads by its `#[RuleSchema]` and is otherwise left
 * out by the same rule {@see ConstValueToRules} follows ({@see UnreadRule}): named in {@see unread()} when
 * it can only refuse values, and giving up the field, named in {@see opaque()}, when it could loosen the
 * rules beside it — as can a rule the type does not pin to one string.
 */
final class ShapeToRuleSet
{
    /** @var array<string, list<string>> */
    private array $unread = [];

    /** @var array<string, string> */
    private array $opaque = [];

    /** @var list<string> */
    private array $unrecoverable = [];

    private readonly DependencyFileSet $dependencyFiles;

    public function __construct(
        private readonly CustomRuleReader $customRules = new CustomRuleReader,
    ) {
        $this->dependencyFiles = new DependencyFileSet;
    }

    public function convert(DType $type): RuleSet
    {
        $this->unread = [];
        $this->opaque = [];
        $this->unrecoverable = [];
        $this->dependencyFiles->clear();

        if (! $type instanceof ArrayShapeT || $type->isList) {
            return new RuleSet;
        }

        $fields = [];
        foreach ($type->fields as $field) {
            $key = (string) $field->key;
            $rules = $this->rulesFor($key, $field->type);
            if (isset($this->opaque[$key])) {
                continue;
            }

            if ($rules !== []) {
                $fields[$key] = $rules;
            } elseif (isset($this->unread[$key])) {
                $this->unrecoverable[] = $key;
            }
        }

        return new RuleSet($fields);
    }

    /**
     * Fields of the last {@see convert()} documented without a rule object it could not read, by field.
     *
     * @return array<string, list<string>>
     */
    public function unread(): array
    {
        return array_diff_key($this->unread, $this->opaque, array_flip($this->unrecoverable));
    }

    /**
     * Fields of the last {@see convert()} whose every rule was a rule object it could not read.
     *
     * @return list<string>
     */
    public function unrecoverable(): array
    {
        return $this->unrecoverable;
    }

    /**
     * Fields the last {@see convert()} gave up, by field: the rule whose content could loosen the rest.
     *
     * @return array<string, string>
     */
    public function opaque(): array
    {
        return $this->opaque;
    }

    /**
     * The rule classes the last {@see convert()} read for their `#[RuleSchema]`.
     *
     * @return list<string>
     */
    public function dependencyFiles(): array
    {
        return $this->dependencyFiles->all();
    }

    /**
     * One field's rules; records what it could not read.
     *
     * @phpstan-impure
     *
     * @return list<ValidationRule>
     */
    private function rulesFor(string $key, DType $value): array
    {
        if ($value instanceof LiteralT && is_string($value->value)) {
            return RuleParsing::tokens($value->value);
        }

        if (! $value instanceof ArrayShapeT) {
            return [];
        }

        $out = [];
        $losses = new RuleLosses;
        foreach ($value->fields as $item) {
            $type = $item->type;
            if ($type instanceof LiteralT) {
                if (is_string($type->value)) {
                    $out[] = RuleParsing::token($type->value);
                }

                continue;
            }

            if (! $type instanceof ClassT) {
                $losses->add('a value this build cannot read', false);

                continue;
            }

            $facts = $this->customRules->read($type->fqcn);
            $this->dependencyFiles->add($facts->file);
            if ($facts->annotated) {
                $out = [...$out, ...$facts->rules];

                continue;
            }

            $unread = UnreadRule::classify($type->fqcn);
            $losses->add($unread['label'], $unread['narrows']);
        }

        if ($losses->opaque() !== null) {
            $this->opaque[$key] = $losses->opaque();
        }

        if ($losses->unread() !== []) {
            $this->unread[$key] = $losses->unread();
        }

        return $out;
    }
}
