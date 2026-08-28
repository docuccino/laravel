<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Validation\RecoveredRequest;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;

/**
 * Documents an action's request from its own `rules()` — the action-class analogue of the Form Request
 * integration. Recovers the rule set statically ({@see ActionRules}), orders it into Laravel's effect
 * sequence, and runs it through the shared chain: body verbs get a request body, read verbs get query
 * parameters. Writes at the integration layer, so docblocks and attributes still override.
 */
final class ActionValidationExtension implements OperationExtension
{
    public function __construct(
        private readonly ActionRules $rules = new ActionRules,
        private readonly RuleOrdering $ordering = new RuleOrdering,
        private readonly RuleSetNormalizer $normalizer = new RuleSetNormalizer,
        private readonly RecoveredRequest $request = new RecoveredRequest,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Request;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $rules = $this->rules->recover($context);
        if ($rules === null || $rules->isEmpty()) {
            return;
        }

        $normalized = $this->normalizer->normalize($rules);
        RuleSetNormalizer::report($normalized, $context);

        $result = $context->validation()->convert($this->ordering->order($normalized), $context->converter());
        if ($result->isEmpty()) {
            return;
        }

        // The action class carries the rules(); its body hoists to a component named after it.
        $this->request->apply($operation, $context, $result, 'laravel-actions', $context->actionRef->class);
    }
}
