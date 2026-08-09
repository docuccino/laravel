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
 * Turns the action's signalled exceptions into error responses (design §Errors): each runs through the
 * resolved {@see ExceptionToResponse} chain (first supports() wins) and merges in via
 * {@see ResponseDraftApplier}. Skipped when `error_responses => 'none'`.
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
     * The throw site (first call-chain frame), falling back to the action when the engine had no usable
     * location — so an explicit throw carries a source just like a synthesized one, never none.
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
