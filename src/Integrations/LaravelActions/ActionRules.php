<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Laravel\Integrations\FormRequest\RulesFromClass;

/**
 * Recovers a request {@see RuleSet} from a laravel-actions action's own `rules()` method, without
 * executing anything — the action-class analogue of a Form Request's `rules()`, converging on the
 * shared {@see RulesFromClass} recovery tail. Returns null when the package would not run the action's
 * rules() for the dispatched method, or when the action defines no `rules()` at all.
 */
final class ActionRules
{
    public function __construct(
        private readonly RulesFromClass $rules = new RulesFromClass,
    ) {}

    public function recover(RouteContext $context): ?RuleSet
    {
        $class = $context->actionRef->class;
        // Only recover rules() when the package would actually run it for the dispatched method:
        // an explicitly-registered method or a WithAttributes action never validates at runtime, so
        // documenting a request body from rules() there would describe an endpoint that does not exist.
        if ($class === null || ! LaravelAction::dispatchesValidation($class, $context->actionRef->method)) {
            return null;
        }

        return $this->rules->analyse($context, $class);
    }
}
