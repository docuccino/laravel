<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Validation\RecoveredRequest;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;

/**
 * Documents a request from its validation rules (design §Phase 4 — FormRequest + inline validate).
 * It recovers a rule set statically — a FormRequest's `rules()` analysed as a constant array, else
 * an inline `$request->validate([...])` / `Validator::make(...)` traced in the action body — orders
 * it into Laravel's effect sequence, and runs it through the shared rule chain. Body verbs
 * (POST/PUT/PATCH) get a request body under the recovered media type (JSON, or multipart once a
 * file rule appears); read verbs (GET/HEAD) get query parameters. Attributes still override, since
 * this writes at the integration layer.
 */
final class ValidationRequestExtension implements OperationExtension
{
    public function __construct(
        private readonly FormRequestRules $formRequest = new FormRequestRules,
        private readonly RuleOrdering $ordering = new RuleOrdering,
        private readonly RecoveredRequest $request = new RecoveredRequest,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Request;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        [$rules, $sourceClass] = $this->recover($context);
        if ($rules === null || $rules->isEmpty()) {
            return;
        }

        $result = $context->validation()->convert($this->ordering->order($rules), $context->converter());
        if ($result->isEmpty()) {
            return;
        }

        // A FormRequest names the source class (its body hoists to a component); an inline
        // `validate()`/`Validator::make()` body has no class to name honestly, so it stays inline.
        $this->request->apply($operation, $context, $result, 'form-request', $sourceClass);
    }

    /**
     * The recovered rule set paired with the single source class it came from (a FormRequest), or null
     * for an inline body with no source class.
     *
     * @return array{0: ?RuleSet, 1: ?string}
     */
    private function recover(RouteContext $context): array
    {
        $fromFormRequest = $this->formRequest->recover($context);
        if ($fromFormRequest !== null && ! $fromFormRequest->isEmpty()) {
            return [$fromFormRequest, $context->formRequestClass];
        }

        $visitor = new InlineRulesVisitor;
        $context->trace($visitor);

        $inline = $visitor->ruleSet();

        foreach ($visitor->unrecoverableFields() as $field) {
            if ($inline->fields[$field] ?? null) {
                continue;
            }
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'validation.rule-unrecoverable',
                message: sprintf('Inline validation field "%s" has no statically recoverable rules; it is omitted from the request schema.', $field),
                help: 'Its rules are a closure, a custom Rule object, or a Rule::when()/conditional descriptor. Express the field with recoverable rules (string/array rules, Rule::enum(), Rule::in(), …) so it is documented.',
                routeSignature: $context->route->signature(),
            ));
        }

        return $inline->isEmpty() ? [null, null] : [$inline, null];
    }
}
