<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Who reads a rules array decides how a rule string in it splits at its pipes. Laravel's validator splits a
 * field's string and takes a string inside a field's list as one rule; laravel-data, reading a Data class's
 * `#[Rule]` or `rules()`, splits a string wherever it is, except one holding a regex ({@see RuleParsing}).
 */
enum RuleDialect
{
    case Laravel;
    case LaravelData;

    /**
     * A field's whole rule string.
     *
     * @return list<ValidationRule>
     */
    public function field(string $rules): array
    {
        return $this === self::Laravel ? RuleParsing::tokens($rules) : RuleParsing::dataTokens($rules);
    }

    /**
     * A string inside a field's list of rules.
     *
     * @return list<ValidationRule>
     */
    public function listed(string $rule): array
    {
        return $this === self::Laravel ? [RuleParsing::token($rule)] : RuleParsing::dataTokens($rule);
    }
}
