<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ExceptionToResponse;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Validation\ResponseDraftApplier;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Provenance\Source;

/**
 * Turns the action's signalled exceptions into error responses (design §Errors) by running each
 * through the resolved {@see ExceptionToResponse} chain
 * (first supports() + non-null wins) and merging the result into the operation via the shared
 * {@see ResponseDraftApplier}. Skipped when the document sets `error_responses => 'none'`.
 */
final class ErrorResponsesExtension implements OperationExtension
{
    public function __construct(
        private readonly ResponseDraftApplier $applier = new ResponseDraftApplier,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Errors;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if ($context->document->errorResponses === 'none') {
            return;
        }

        foreach ($context->analysis()->throws as $throw) {
            if ($throw->disposition !== ThrowDisposition::Signal) {
                continue;
            }

            $mapped = $context->mapThrow($throw);
            if ($mapped !== null) {
                $this->applier->apply($operation, $mapped->draft, $mapped->mapper->producer(), $this->throwSource($context, $throw));
            }
        }
    }

    /**
     * The provenance source for an explicit throw: its recovered throw site (the first call-chain
     * frame), falling back to the action itself when the engine reported no usable location — so an
     * explicit-throw error response carries a source exactly as a synthesized one does (arch review
     * PIN 4), rather than a sourceless contribution.
     */
    private function throwSource(RouteContext $context, ThrownException $throw): ?Source
    {
        $frame = $throw->callChain[0] ?? null;
        if ($frame !== null && $frame->location->file !== '') {
            return $context->sourceAt($frame->location, $frame->symbol === '' ? null : $frame->symbol);
        }

        return $context->actionSource();
    }
}
