<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `json` (Scribe-parity, validation §4 #8): the value is a STRING carrying a JSON document, so the
 * honest JSON-Schema mapping is `type: string` + `contentMediaType: application/json` — the standard
 * keyword for "this string contains JSON". Also unblocks spatie's `#[Json]` attribute token, which
 * previously recovered a `json` rule that dead-ended in a diagnostic. Defaults an untyped field to string.
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
