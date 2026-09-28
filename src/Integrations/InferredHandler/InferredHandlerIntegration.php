<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

/**
 * Entry point for the inferred exception-handler tier (design §6). Always on: it documents whatever error
 * contract the app actually implements — the exception map every throw is translated through first, render
 * callbacks, exception `render()`, `Responsable` exceptions, and the `respond()` callback every rendered
 * error passes through — and defers to the next tier for anything it can't fold to a JSON response. The
 * translator, the mapper and the finalizer are container-resolved so their {@see HandlerReflector} gets the
 * booted exception handler.
 */
final class InferredHandlerIntegration
{
    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            ExceptionMapTranslator::class,
            InferredHandlerExceptionToResponse::class,
            RespondCallbackFinalizer::class,
            RenderCallbackDigestContributor::class,
            RenderCallbackSkipTransformer::class,
            // The log is registered as an extension in its own right, not just injected: it is the
            // RouteNoteCollector the pipeline drains each route's deferral notes into, and only a resolved
            // extension reaches that chain.
            HandlerDeferralLog::class,
            HandlerDeferralSummaryTransformer::class,
        ];
    }
}
