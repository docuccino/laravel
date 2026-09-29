<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * `alpha`, `alpha_num`, `alpha_dash` → a string `pattern`. Under `:ascii` Laravel's ASCII class is exact;
 * without it any script's letters, marks and numbers pass, and `\p{…}` — the only exact spelling — is read
 * as a literal `p` wherever a consumer compiles the pattern without ECMA-262's `u` flag (OAS 3.0's dialect,
 * many generated validators). So the plain form admits the ASCII class plus any non-ASCII character:
 * exact over ASCII, wider beyond it, and the same set with or without the flag.
 */
final class AlphaRuleTransformer implements RuleTransformer
{
    /**
     * @var array<string, string>
     */
    private const ASCII = [
        'alpha' => '[a-zA-Z]',
        'alpha_num' => '[a-zA-Z0-9]',
        'alpha_dash' => '[a-zA-Z0-9_-]',
    ];

    public function supports(ValidationRule $rule): bool
    {
        return isset(self::ASCII[$rule->name]);
    }

    public function handledRuleNames(): array
    {
        return array_keys(self::ASCII);
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        if (! $field->has('type')) {
            $field->setType('string');
        }

        $class = self::ASCII[$rule->name];

        // Laravel reads the first parameter and nothing else: `ascii` exactly, or the any-script form.
        $field->set('pattern', $rule->parameter() === 'ascii' ? '^'.$class.'+$' : '^(?:'.$class.'|[^\\x00-\\x7F])+$');
    }
}
