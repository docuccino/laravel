<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

/**
 * Entry point for the inferred exception-handler tier (design §6). Always on: it documents whatever error
 * contract the app actually implements — render callbacks, exception `render()`, `Responsable` exceptions —
 * and defers to the next tier for anything it can't fold to a JSON response. The mapper is
 * container-resolved so its {@see HandlerReflector} gets the booted exception handler.
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
            RenderCallbackDigestContributor::class,
            RenderCallbackSkipTransformer::class,
            HandlerDeferralSummaryTransformer::class,
        ];
    }
}
