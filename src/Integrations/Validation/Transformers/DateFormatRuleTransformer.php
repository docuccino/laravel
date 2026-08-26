<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Validation\Transformers;

use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Support\DateWireFormat;

/**
 * `date_format:Y-m-d` → a string schema carrying the raw PHP format in its description, so a reader keeps
 * the exact contract, plus the OAS `format` the pattern's own values satisfy where {@see DateWireFormat}
 * has one for it. The app states the accepted wire format outright here, so this is the most specific
 * source there is and nothing displaces it.
 *
 * The wire format is the one thing the schema cannot carry, so the example is proposed here rather than
 * derived from `format` — which would document an ISO value a `d/m/Y` endpoint rejects.
 */
final class DateFormatRuleTransformer implements RuleTransformer
{
    public function supports(ValidationRule $rule): bool
    {
        return in_array($rule->name, $this->handledRuleNames(), true);
    }

    public function handledRuleNames(): array
    {
        return ['date_format'];
    }

    public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
    {
        $field->setType('string');

        $pattern = $rule->parameter() ?? '';
        if ($pattern === '') {
            return;
        }

        $oas = DateWireFormat::oas($pattern);
        if ($oas !== null) {
            $field->set('format', $oas);
        } else {
            // Nothing names this pattern's values, so a coarser `date` rule's guess goes with it rather
            // than outliving the rule that stated the real contract.
            $field->remove('format');
        }

        if (! $field->has('description')) {
            $field->set('description', DateWireFormat::expected($pattern));
        }

        $field->proposeExample(DateWireFormat::example($pattern));
    }
}
