<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;

/**
 * `date_wire:<PHP date() format>` — the wire format a date-typed property really accepts, stated outright
 * by the recovering integration from the most specific source it has, because Laravel's rule vocabulary
 * has no word for it. Runs straight after the type rules and REPLACES what they left: a bare `date` rule
 * accepts anything non-relative `strtotime` parses, so the `format: date` {@see TypeRuleTransformer} pairs
 * with it is only its reading of intent where nothing better is known — which is why that transformer sets
 * a format only on a field that hasn't got one, and why this rule outranks what it left.
 *
 * The format is answered exactly as {@see DateFormatRuleTransformer} answers the rule where the app states
 * it outright, from the one policy in {@see DateWireFormat}: a `format` keyword only where the pattern's
 * own values satisfy it, else a string with the pattern named, and either way an example rendered WITH the
 * pattern rather than derived from the keyword. `U` is the one format that is not a string at all, so the
 * coarse rule's `format` goes with the type it belonged to.
 */
final class DateWireRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['date_wire'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $format = $rule->parameter();
        if ($format === null || $format === '') {
            return;
        }

        if ($format === DateWireFormat::UNIX) {
            $field->setType('integer');
            $field->remove('format');
            $field->set('description', DateWireFormat::TIMESTAMP_NOTE);

            return;
        }

        $field->setType('string');

        $oas = DateWireFormat::oas($format);
        if ($oas !== null) {
            $field->set('format', $oas);
        } else {
            // Nothing names this pattern's values, so the coarse rule's guess goes with it rather than
            // outliving the rule that displaced it.
            $field->remove('format');
            $field->set('description', DateWireFormat::expected($format));
        }

        $field->proposeExample(DateWireFormat::example($format));
    }
}
