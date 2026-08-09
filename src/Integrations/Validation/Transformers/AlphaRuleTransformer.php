<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `alpha`, `alpha_num`, `alpha_dash` → a string `pattern`. The ECMA-262 patterns describe the ASCII form
 * Laravel enforces under `:ascii`, which is also the common default, so the `:ascii` parameter needs no
 * special handling. An explicit type is preserved; only untyped fields default to string.
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
