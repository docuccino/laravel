<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `regex:/…/` → a string schema with a `pattern` keyword. The PHP delimiters and trailing flags
 * are stripped so the pattern is a bare ECMA-262 regex (what JSON Schema expects); a flagged or
 * unusual delimiter is kept verbatim rather than mangled.
 */
final class RegexRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['regex'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        if (! $field->has('type')) {
            $field->setType('string');
        }

        $pattern = implode(',', $rule->parameters);
        $field->set('pattern', $this->normalize($pattern));
    }

    private function normalize(string $pattern): string
    {
        if (strlen($pattern) < 2) {
            return $pattern;
        }

        $delimiter = $pattern[0];
        $closing = match ($delimiter) {
            '(' => ')',
            '{' => '}',
            '[' => ']',
            '<' => '>',
            default => $delimiter,
        };

        $end = strrpos($pattern, $closing);
        if ($end === false || $end === 0) {
            return $pattern;
        }

        return substr($pattern, 1, $end - 1);
    }
}
