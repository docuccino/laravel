<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Validation\RuleSet;

/**
 * Recovers a request {@see RuleSet} from a FormRequest type-hinted on the action. The class is resolved once
 * by the route context ({@see RouteContext::$formRequestClass}, shared with the implicit-403 authorize probe
 * so neither reaches into the other), then its `rules()` goes through {@see RulesFromClass}.
 */
final class FormRequestRules
{
    public function __construct(
        private readonly RulesFromClass $rules = new RulesFromClass,
    ) {}

    public function recover(RouteContext $context): ?RuleSet
    {
        $formRequest = $context->formRequestClass;
        if ($formRequest === null) {
            return null;
        }

        return $this->rules->analyse($context, $formRequest);
    }
}
