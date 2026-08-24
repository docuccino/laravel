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
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;

/**
 * Documents a request from its validation rules. Recovers a rule set statically — a FormRequest's
 * `rules()` read as a constant array, else an inline `$request->validate([...])`/`Validator::make(…)`
 * traced in the action body — orders it into Laravel's effect sequence, and runs it through the shared rule
 * chain. Body verbs get a request body under the recovered media type (JSON, or multipart once a file rule
 * appears); read verbs get query parameters. Attributes still override, as this writes at the integration
 * layer — and a `#[BodyParameter]` overrides by PATCHING the body this wrote, which is why the
 * attribute body extension runs behind this one.
 */
final class ValidationRequestExtension implements OperationExtension
{
    public function __construct(
        private readonly FormRequestRules $formRequest = new FormRequestRules,
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
        [$rules, $sourceClass] = $this->recover($context);
        if ($rules === null || $rules->isEmpty()) {
            return;
        }

        $result = $context->validation()->convert($this->ordering->order($this->normalizer->normalize($rules)), $context->converter());
        if ($result->isEmpty()) {
            return;
        }

        // A FormRequest names its source class, so its body hoists to a component; an inline body has no
        // class to name honestly and stays inline.
        $this->request->apply($operation, $context, $result, 'form-request', $sourceClass);
    }

    /**
     * The rule set plus the FormRequest class it came from; the class is null for an inline body.
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
        $context->recordDependencyFiles($visitor->dependencyFiles());

        $inline = $visitor->ruleSet();

        foreach ($visitor->unrecoverableFields() as $field) {
            if ($inline->fields[$field] ?? null) {
                continue;
            }
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'validation.rule-unrecoverable',
                message: sprintf('Inline validation field "%s" has no statically recoverable rules; it is omitted from the request schema.', $field),
                help: 'Its rules are a closure, a custom Rule object with no #[RuleSchema], or a Rule::when()/conditional descriptor. Express the field with recoverable rules (string/array rules, Rule::enum(), Rule::in(), …), or annotate the rule class with #[RuleSchema], so it is documented.',
                routeSignature: $context->route->signature(),
            ));
        }

        foreach ($visitor->widenedFields() as $field) {
            $context->components->addDiagnostic(new Diagnostic(
                severity: Severity::Info,
                code: 'validation.rule-values-unread',
                message: sprintf('Inline validation field "%s" states values this build cannot read, so that constraint is left off the request schema; the rest of its rules are documented.', $field),
                help: 'A value the rule states is not written at the rule — it comes from a call, a variable or a spread — and a partial list would make a client reject a value the API accepts. Write every value where the rule is, which is what settles it; an overlay corrects the document instead, and this notice keeps naming the rule.',
                routeSignature: $context->route->signature(),
            ));
        }

        return $inline->isEmpty() ? [null, null] : [$inline, null];
    }
}
