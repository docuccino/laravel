<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Support;

use Docuccino\Core\Extensions\Validation\ValidationRule;

/**
 * Statically parses recovered Laravel rule strings into {@see ValidationRule}s — never executing
 * anything. A pipe string (`'required|max:100'`) splits into tokens; a token (`'in:a,b'`) splits
 * into a name plus comma parameters. Array-form rules feed their string items through {@see token()}.
 */
final class RuleParsing
{
    /**
     * @return list<ValidationRule>
     */
    public static function tokens(string $pipe): array
    {
        $out = [];
        foreach (explode('|', $pipe) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $out[] = self::token($token);
            }
        }

        return $out;
    }

    public static function token(string $token): ValidationRule
    {
        $colon = strpos($token, ':');
        if ($colon === false) {
            return ValidationRule::of($token);
        }

        $name = substr($token, 0, $colon);
        $parameters = array_values(array_filter(
            array_map('trim', explode(',', substr($token, $colon + 1))),
            static fn (string $parameter): bool => $parameter !== '',
        ));

        return ValidationRule::of($name, $parameters);
    }
}
