<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Support\PortablePattern;

/**
 * `regex:/…/` → a string schema whose `pattern` is the body read as {@see PortablePattern} reads it: exact,
 * or wider where PHP's `u` makes a class escape match every script or its `$` without `D` takes a final `\n`.
 * A modifier changing what matches (`i`, `m`, `s`, `x`), a body no pattern states truly, or a regex PHP
 * cannot compile publishes no pattern.
 */
final class RegexRuleTransformer implements RuleTransformer
{
    /** The PCRE modifiers that change which strings match; dropping any other (`u`, `A`, `D`, `U`, …) never narrows it. */
    private const MATCH_CHANGING = ['i', 'm', 's', 'x'];

    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['regex'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        if (! $field->has('type')) {
            $field->setType('string');
        }

        $regex = implode(',', $rule->parameters);
        [$body, $modifiers] = $this->split($regex);

        $dropped = array_values(array_intersect(self::MATCH_CHANGING, str_split($modifiers)));
        if ($dropped !== []) {
            $context->diagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'validation.regex-modifier',
                message: sprintf(
                    'The regex on field "%s" carries the /%s modifier, which a JSON Schema pattern cannot state, so no pattern is published.',
                    $field->path(),
                    implode('', $dropped),
                ),
                help: 'Spell what the modifier does inside the pattern — `[a-zA-Z]` for `/i`, `[\s\S]` for a dot under `/s` — '
                    .'and the pattern is published. Without the modifier the pattern would refuse values your API accepts, '
                    .'so the field is published without one rather than wrongly.',
            ));

            return;
        }

        // Laravel runs the regex as written, a search anchored only where the author anchored it.
        $pattern = @preg_match($regex, '') === false
            ? null
            : PortablePattern::translate($body, unicode: str_contains($modifiers, 'u'), anchors: true, endOnly: str_contains($modifiers, 'D'));

        if ($pattern !== null) {
            $field->set('pattern', $pattern);

            return;
        }

        $context->diagnostic(new Diagnostic(
            severity: Severity::Info,
            code: 'validation.regex-unportable',
            message: sprintf(
                'The regex on field "%s" reads differently as a JSON Schema pattern, so no pattern is published.',
                $field->path(),
            ),
            help: 'Spell it with ASCII literals, escaped syntax characters, bracket classes such as `[0-9]`, anchors, groups, '
                .'alternation and quantifiers, and it is published. PHP and a JSON Schema validator part on `.`, on `\s` and `\b` '
                .'under `/u`, on `\\B` without it, on a count such as `{4}` over a negated class or a class escape, and on PHP-only syntax: inline '
                .'flags, lookarounds, possessive quantifiers.',
        ));
    }

    /**
     * The pattern body and the modifiers after its closing delimiter.
     *
     * @return array{string, string}
     */
    private function split(string $pattern): array
    {
        if (strlen($pattern) < 2) {
            return [$pattern, ''];
        }

        $delimiter = $pattern[0];
        $closing = match ($delimiter) {
            '(' => ')',
            '{' => '}',
            '[' => ']',
            '<' => '>',
            default => $delimiter,
        };

        $end = strrpos($pattern, $closing);
        if ($end === false || $end === 0) {
            return [$pattern, ''];
        }

        return [substr($pattern, 1, $end - 1), substr($pattern, $end + 1)];
    }
}
