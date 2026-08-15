<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Laravel\Integrations\FormRequest\RulesFromClass;

/**
 * Recovers a request {@see RuleSet} from an action's own `rules()` — the action-class analogue of a Form
 * Request's, sharing the {@see RulesFromClass} tail. Null when the action defines no `rules()`, or when the
 * package wouldn't run it for the dispatched method.
 */
final class ActionRules
{
    public function __construct(
        private readonly RulesFromClass $rules = new RulesFromClass,
    ) {}

    public function recover(RouteContext $context): ?RuleSet
    {
        $class = $context->actionRef->class;
        // Which traits an action carries is a hierarchy question, and it decides whether a request body
        // is documented at all — recorded before the decline, so "no body" goes stale too.
        $context->recordDependencyFiles(DeclarationFiles::of($class));

        // An explicitly-registered method or a WithAttributes action never validates at runtime, so a
        // request body built from rules() there would describe an endpoint that doesn't exist.
        if ($class === null || ! LaravelAction::dispatchesValidation($class, $context->actionRef->method)) {
            return null;
        }

        return $this->rules->analyse($context, $class);
    }
}
