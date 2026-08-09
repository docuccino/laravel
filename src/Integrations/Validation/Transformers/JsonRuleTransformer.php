<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `json` — and spatie's `#[Json]`, which recovers the same rule. The value is a string carrying a JSON
 * document, so it maps to `type: string` + `contentMediaType: application/json`, the standard keyword for
 * exactly that. Untyped fields default to string.
 */
final class JsonRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return $rule->name === 'json';
    }

    public function handledRuleNames(): array
    {
        return ['json'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        if (! $field->has('type')) {
            $field->setType('string');
        }

        $field->set('contentMediaType', 'application/json');
    }
}
