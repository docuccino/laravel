<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

/**
 * Entry point for the `lorisleiva/laravel-actions` integration, registered behind a `trait_exists` guard
 * on the package's `AsController` trait so docuccino/laravel never hard-requires it.
 *
 * Route-method remapping (invokable action → `asController()`/`handle()`) happens earlier, in the route
 * reflector via {@see LaravelAction}. These extensions then document the resolved method's `rules()`, its
 * `authorize()` 403, and the `text/html` representation when the action defines `htmlResponse()`. The
 * `jsonResponse()` success-body redirect is a gated {@see LaravelActionResponseAnalysis} the adapter's
 * inferred-responses extension reads off the context chain — one source, so nothing stale leaks and a
 * disabled integration never shapes the 200 body.
 */
final class LaravelActionsIntegration
{
    /**
     * The probe is injectable so the gated-off branch stays testable where the package is present.
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
            LaravelActionResponseAnalysis::class,
        ];
    }
}
