<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

/**
 * Entry point for the inferred exception-handler tier (design §6 flagship). Always on: it documents
 * whatever error contract the app actually implements (render callbacks, exception `render()`,
 * `Responsable` exceptions) and stays inert — deferring to the next tier — for an app that renders
 * errors in a way it cannot fold to a JSON response. The mapper is container-resolved so its
 * {@see HandlerReflector} receives the booted exception handler.
 */
final class InferredHandlerIntegration
{
    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            InferredHandlerExceptionToResponse::class,
            // Environment-digest seam (A4): the registered render-callback set feeds the document-level
            // fragment-cache digest so adding a render handler re-documents the inferred error tier.
            RenderCallbackDigestContributor::class,
            // Kills the tier's silence: reports one diagnostic per registered-but-unanalysable render
            // callback (runs once per build regardless of routes).
            RenderCallbackSkipTransformer::class,
            // Collapses deferral noise: one summary diagnostic per callback that genuinely could not
            // fold a response (naming count + first few exception types), replacing per-exception spam.
            HandlerDeferralSummaryTransformer::class,
        ];
    }
}
