<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\FormRequest;

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
 * attribute body extension runs behind this one — and why the recovery notes read the declarations for
 * wherever these rules landed before they say a field went undocumented, since one naming a field
 * publishes it after this runs.
 *
 * @phpstan-import-type CopySource from CopiedInputs
 */
final class ValidationRequestExtension implements OperationExtension
{
    public function __construct(
        private readonly FormRequestRules $formRequest = new FormRequestRules,
        private readonly RuleOrdering $ordering = new RuleOrdering,
        private readonly RuleSetNormalizer $normalizer = new RuleSetNormalizer,
        private readonly RecoveredRequest $request = new RecoveredRequest,
        private readonly CopiedInputs $copied = new CopiedInputs,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Request;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        [$rules, $sourceClass, $copied] = $this->recover($context);
        if ($rules === null || $rules->isEmpty()) {
            return;
        }

        $normalized = $this->normalizer->normalize($rules, RecoveredRequest::publishesVariants($context, $sourceClass));
        RuleSetNormalizer::report($normalized, $context, $sourceClass);

        // A key overwritten with a header, query value or route parameter before validation validates that
        // part of the request, never the body; its rules are published there ({@see CopiedInputParameters}).
        $normalized = $this->copied->move($operation, $copied, $normalized);

        $result = $context->validation()->convert($this->ordering->order($normalized), $context->converter());
        if ($result->isEmpty()) {
            return;
        }

        // A FormRequest names its source class, so its body hoists to a component; an inline body has no
        // class to name honestly and stays inline.
        $this->request->apply($operation, $context, $result, 'form-request', $sourceClass);
    }

    /**
     * The rule set, the FormRequest class it came from (null for an inline body), and the keys copied into
     * the input before it validates ({@see CopiedInputs}).
     *
     * @return array{0: ?RuleSet, 1: ?string, 2: array<string, CopySource>}
     */
    private function recover(RouteContext $context): array
    {
        $fromFormRequest = $this->formRequest->recover($context);
        if ($fromFormRequest !== null && ! $fromFormRequest->isEmpty() && $context->formRequestClass !== null) {
            return [$fromFormRequest, $context->formRequestClass, $this->copied->of($context, $context->formRequestClass)];
        }

        $visitor = new InlineRulesVisitor;
        $context->trace($visitor);
        $context->recordDependencyFiles($visitor->dependencyFiles());

        $inline = $visitor->ruleSet();

        // An inline body has no source class, so the route's own attribute bag is the whole of what can
        // still document a field this trace could not read.
        $notes = new UnrecoveredRules(RecoveredRequest::declaredFields($context, null));

        foreach ($visitor->unrecoverableFields() as $field) {
            if ($inline->fields[$field] ?? null) {
                continue;
            }

            $notes->unrecoverable($context, $field, sprintf('Inline validation field "%s" has no statically recoverable rules; it is omitted from %s.', $field, RecoveredRequest::destination($context)));
        }

        foreach ($visitor->widenedFields() as $field) {
            $notes->widened($context, $field, sprintf('Inline validation field "%s" states values this build cannot read, so that constraint is left off %s; the rest of its rules are documented.', $field, RecoveredRequest::destination($context)));
        }

        return $inline->isEmpty() ? [null, null, []] : [$inline, null, $this->copied->ofInline($context, $visitor->validations())];
    }
}
