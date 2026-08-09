<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

/**
 * The single entry point for the `lorisleiva/laravel-actions` integration (Phase 5c). Registered
 * behind a `trait_exists` guard on the package's `AsController` trait, so docuccino/laravel never
 * hard-requires it. The route-method remapping (an invokable action → its `asController()`/`handle()`
 * method) is applied earlier, in the route reflector via {@see LaravelAction}; these extensions then
 * document the resolved method's request (`rules()`) and its `authorize()` 403, plus the `text/html`
 * representation of an action defining `htmlResponse()`. The `jsonResponse()` success-body redirect is
 * contributed as a gated {@see LaravelActionResponseAnalysis} the adapter's inferred-responses extension
 * reads off the context chain (single-source, so no stale untransformed keywords leak, and a disabled
 * integration never shapes the 200 body).
 */
final class LaravelActionsIntegration
{
    /**
     * The class-presence probe is injectable so the gated-off branch is testable where the package
     * is in fact present.
     *
     * @param  (callable(string): bool)|null  $probe
     */
    public static function installed(?callable $probe = null): bool
    {
        $probe ??= static fn (string $class): bool => trait_exists($class);

        return $probe(LaravelAction::CONTROLLER_TRAIT);
    }

    /**
     * @return list<class-string>
     */
    public static function extensions(): array
    {
        return [
            ActionValidationExtension::class,
            ActionAuthorizeResponsesExtension::class,
            ActionHtmlResponseExtension::class,
            // The success-body analysis redirect (jsonResponse → the real JSON wire shape): a gated
            // ResponseAnalysisTarget, so a disabled integration never redirects the 200 body inference.
            LaravelActionResponseAnalysis::class,
        ];
    }
}
