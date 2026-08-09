<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Laravel\Integrations\Support\RuleParsing;

/**
 * Turns a `rules()` method's inferred return type (an {@see ArrayShapeT} of `field => rule`) into a
 * {@see RuleSet}. This is the FormRequest path: the engine analyses `rules()` statically (its literal
 * array becomes a constant array shape), so pipe-string and array-of-string rule forms are recovered
 * without ever instantiating the request or executing `rules()`.
 */
final class ShapeToRuleSet
{
    public function convert(DType $type): RuleSet
    {
        if (! $type instanceof ArrayShapeT || $type->isList) {
            return new RuleSet;
        }

        $fields = [];
        foreach ($type->fields as $field) {
            $rules = $this->rulesFor($field->type);
            if ($rules !== []) {
                $fields[(string) $field->key] = $rules;
            }
        }

        return new RuleSet($fields);
    }

    /**
     * @return list<ValidationRule>
     */
    private function rulesFor(DType $value): array
    {
        if ($value instanceof LiteralT && is_string($value->value)) {
            return RuleParsing::tokens($value->value);
        }

        if ($value instanceof ArrayShapeT) {
            $out = [];
            foreach ($value->fields as $item) {
                if ($item->type instanceof LiteralT && is_string($item->type->value)) {
                    $out[] = RuleParsing::token($item->type->value);
                }
            }

            return $out;
        }

        return [];
    }
}
