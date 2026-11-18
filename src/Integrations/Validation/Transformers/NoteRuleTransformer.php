<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Framework rules whose constraint no schema keyword states truly, so the constraint is a note beneath the
 * field and the schema stays as wide as the rest of the rules leave it. Each is here for a reason a keyword
 * would get wrong: another field's value (`same`, `different`, `in_array`, `date_equals`), Laravel's loose
 * `in_array()` membership (`contains`, `doesnt_contain`), a check made at run time (`active_url` resolves
 * the host), a Unicode or byte reading a pattern would narrow (`lowercase`, `uppercase`, `ascii`,
 * `encoding`), and a PHP regex whose portable reading may be wider, which inside a `not` would refuse values
 * the server takes (`not_regex`).
 */
final class NoteRuleTransformer implements RuleTransformer
{
    private const NAMES = [
        'active_url', 'array_keys', 'ascii', 'contains', 'date_equals', 'different', 'doesnt_contain', 'encoding',
        'in_array', 'in_array_keys', 'lowercase', 'not_regex', 'same', 'uppercase',
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
        // A URL is a string whatever else holds; nothing about its shape is narrower than the server's.
        if ($rule->name === 'active_url' && ! $field->has('type')) {
            $field->setType('string');
        }

        $note = self::describe($rule);
        if ($note !== null) {
            $field->note($note);
        }
    }

    /** The sentence the rule states, or null where it carries no parameter to state it with. */
    public static function describe(ValidationRule $rule): ?string
    {
        $params = $rule->parameters;
        $list = implode(', ', $params);

        return match ($rule->name) {
            'active_url' => 'Must be a URL whose host resolves in DNS.',
            'ascii' => 'Must contain only ASCII characters.',
            'lowercase' => 'Must be lowercase.',
            'uppercase' => 'Must be uppercase.',
            default => $params === [] ? null : match ($rule->name) {
                'contains' => sprintf('Must contain each of: %s.', $list),
                'doesnt_contain' => sprintf('Must contain none of: %s.', $list),
                'in_array_keys' => sprintf('Must contain at least one of the keys: %s.', $list),
                // Laravel 13's: no key other than these, which `array:a,b` states too and neither publishes.
                'array_keys' => sprintf('May contain only the keys: %s.', $list),
                'same' => sprintf('Must match %s.', $params[0]),
                'different' => count($params) === 1 ? sprintf('Must differ from %s.', $params[0]) : sprintf('Must differ from each of %s.', $list),
                'in_array' => sprintf('Must be one of the values sent in %s.', preg_replace('/\.\*$/', '', $params[0])),
                'date_equals' => sprintf('Must be the same date as %s.', $params[0]),
                'encoding' => sprintf('Must be valid %s.', $params[0]),
                // A regex may itself contain a comma, which the rule string split on.
                'not_regex' => sprintf('Must not match %s.', implode(',', $params)),
                default => null,
            },
        };
    }
}
