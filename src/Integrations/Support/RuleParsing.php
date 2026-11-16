<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Statically parses recovered Laravel rule strings into {@see ValidationRule}s — never executing
 * anything. A pipe string (`'required|max:100'`) splits into tokens; a token (`'in:a,b'`) splits
 * into a name plus its parameters, read as Laravel reads them. Where a string is split, and how, depends on
 * who reads it ({@see RuleDialect}).
 */
final class RuleParsing
{
    /**
     * A rule string as Laravel's validator splits a field's: at every pipe.
     *
     * @return list<ValidationRule>
     */
    public static function tokens(string $pipe): array
    {
        return array_map(self::token(...), self::pieces(explode('|', $pipe)));
    }

    /**
     * A rule string as laravel-data splits one, wherever it is written: at every pipe, unless `regex:` appears
     * anywhere in it, when the whole string is one rule, so a pattern's `|` survives.
     *
     * @return list<ValidationRule>
     */
    public static function dataTokens(string $rules): array
    {
        return array_map(self::token(...), self::dataPieces($rules));
    }

    /**
     * The rule strings {@see dataTokens()} reads, before each is parsed.
     *
     * @return list<string>
     */
    public static function dataPieces(string $rules): array
    {
        return self::pieces(str_contains($rules, 'regex:') ? [$rules] : explode('|', $rules));
    }

    public static function token(string $token): ValidationRule
    {
        $colon = strpos($token, ':');
        if ($colon === false) {
            return ValidationRule::of($token);
        }

        $name = substr($token, 0, $colon);

        return ValidationRule::of($name, self::parameters($name, substr($token, $colon + 1)));
    }

    /**
     * Each piece trimmed, and a blank one dropped, as Laravel skips an empty rule.
     *
     * @param  list<string>  $pieces
     * @return list<string>
     */
    private static function pieces(array $pieces): array
    {
        return array_values(array_filter(array_map(trim(...), $pieces), static fn (string $piece): bool => $piece !== ''));
    }

    /**
     * A token's parameters as Laravel's `ValidationRuleParser` reads them: a regex is one parameter, commas,
     * quotes and all, and anything else is a CSV row, so `in:"a,b",""` lists `a,b` and the empty string.
     *
     * @return list<string>
     */
    private static function parameters(string $name, string $parameters): array
    {
        if (in_array(strtolower(trim($name)), ['regex', 'not_regex', 'notregex'], true)) {
            return [trim($parameters)];
        }

        if (trim($parameters) === '') {
            return [];
        }

        return array_map(
            static fn (?string $parameter): string => trim((string) $parameter),
            str_getcsv($parameters, escape: '\\'),
        );
    }
}
