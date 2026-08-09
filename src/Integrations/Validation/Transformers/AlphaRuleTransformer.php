<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * The character-class rules `alpha`, `alpha_num`, `alpha_dash` → a string `pattern` (Scribe-parity,
 * validation §4 #8). The canonical ECMA-262 patterns document the ASCII form Laravel enforces under
 * `:ascii` and the common default case; the `:ascii` parameter is accepted and needs no change. An
 * explicit type is preserved (the field defaults to string only when untyped).
 */
final class AlphaRuleTransformer implements RuleTransformer
{
    /**
     * @var array<string, string>
     */
    private const PATTERNS = [
        'alpha' => '^[a-zA-Z]+$',
        'alpha_num' => '^[a-zA-Z0-9]+$',
        'alpha_dash' => '^[a-zA-Z0-9_-]+$',
    ];

    public function supports(ValidationRule $rule): bool
    {
        return isset(self::PATTERNS[$rule->name]);
    }

    public function handledRuleNames(): array
    {
        return array_keys(self::PATTERNS);
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        if (! $field->has('type')) {
            $field->setType('string');
        }

        $field->set('pattern', self::PATTERNS[$rule->name]);
    }
}
