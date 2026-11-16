<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `mac_address`, `hex_color` → the string `pattern` Laravel checks them against, which no JSON Schema
 * `format` names. Each is exact: `hex_color` is Laravel's own regex with its case spelled out and, lacking
 * `D`, the final newline `$` admits; `mac_address` is what `FILTER_VALIDATE_MAC` takes, written out per
 * separator so it needs no backreference.
 */
final class FixedPatternRuleTransformer implements RuleTransformer
{
    /**
     * Rule → its pattern and a value it takes (RFC 7042's documentation MAC range).
     *
     * @var array<string, array{pattern: string, example: string}>
     */
    private const PATTERNS = [
        'mac_address' => [
            'pattern' => '^(?:[0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5}|[0-9a-fA-F]{2}(?:-[0-9a-fA-F]{2}){5}|[0-9a-fA-F]{4}(?:\\.[0-9a-fA-F]{4}){2})$',
            'example' => '00:00:5E:00:53:01',
        ],
        'hex_color' => [
            'pattern' => '^#(?:(?:[0-9a-fA-F]{3}){1,2}|(?:[0-9a-fA-F]{4}){1,2})\\n?$',
            'example' => '#000000',
        ],
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
        // Neither takes anything but a string: a number never holds a separator or a `#`.
        if (! $field->has('type')) {
            $field->setType('string');
        }

        $field->set('pattern', self::PATTERNS[$rule->name]['pattern']);
        $field->proposeExample(self::PATTERNS[$rule->name]['example']);
    }
}
