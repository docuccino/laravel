<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `required_if`, `required_unless`, `required_with[_all]`, `required_without[_all]`. The field is required
 * only under a runtime condition OpenAPI can't express, so it stays optional — the safe floor — and the
 * condition becomes a "Required when …" description note.
 */
final class ConditionalRequiredRuleTransformer implements RuleTransformer
{
    private const NAMES = [
        'required_if',
        'required_unless',
        'required_with',
        'required_with_all',
        'required_without',
        'required_without_all',
    ];

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return self::NAMES;
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $note = $this->describe($rule);
        if ($note === null) {
            return;
        }

        $existing = $field->get('description');
        $field->set('description', is_string($existing) && $existing !== '' ? $existing.' '.$note : $note);
    }

    private function describe(ValidationRule $rule): ?string
    {
        $params = $rule->parameters;
        if ($params === []) {
            return null;
        }

        $field = $params[0];
        $values = array_slice($params, 1);
        $list = implode(', ', $params);
        // One field is neither "any of" nor "all of" anything: each of the four reads as the one sentence.
        $one = count($params) === 1;

        return match ($rule->name) {
            'required_if' => $values === []
                ? sprintf('Required when %s is present.', $field)
                : sprintf('Required when %s is %s.', $field, implode(' or ', $values)),
            'required_unless' => sprintf('Required unless %s is %s.', $field, $values === [] ? 'present' : implode(' or ', $values)),
            'required_with', 'required_with_all' => $one
                ? sprintf('Required when %s is present.', $field)
                : sprintf($rule->name === 'required_with' ? 'Required when any of %s is present.' : 'Required when %s are all present.', $list),
            'required_without', 'required_without_all' => $one
                ? sprintf('Required when %s is absent.', $field)
                : sprintf($rule->name === 'required_without' ? 'Required when any of %s is absent.' : 'Required when %s are all absent.', $list),
            default => null,
        };
    }
}
