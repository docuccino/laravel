<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

use Docuccino\Core\Inference\ActionRef;
use ReflectionMethod;

/**
 * Recognises a `lorisleiva/laravel-actions` action class (it carries the package's `AsController`
 * trait, directly or via the umbrella `AsAction` trait) and exposes the integration's per-route facts:
 * whether the package would run its `rules()`/`authorize()` for the dispatched method, the
 * `jsonResponse()` success-body redirect, and whether it defines `htmlResponse()`. The route-IDENTITY
 * remap (which method an invokable route dispatches) is a non-toggleable route-reflection probe living
 * in the routing layer instead (Docuccino\Laravel\Routing\LaravelActionRouteMethod), so it runs
 * regardless of the integration toggle.
 *
 * All checks are guarded by the trait's presence, so this is inert when the package is absent.
 */
final class LaravelAction
{
    public const CONTROLLER_TRAIT = 'Lorisleiva\\Actions\\Concerns\\AsController';

    /** The trait that opts an action out of the package's automatic request validation. */
    public const WITH_ATTRIBUTES_TRAIT = 'Lorisleiva\\Actions\\Concerns\\WithAttributes';

    /** The methods the package treats as non-explicit (it remaps invokable routes onto these). */
    private const DISPATCH_METHODS = ['asController', 'handle', '__invoke'];

    /** Whether an FQCN is a laravel-actions action used as a controller (carries the AsController trait). */
    public static function isAction(string $fqcn): bool
    {
        if (! trait_exists(self::CONTROLLER_TRAIT)) {
            return false;
        }

        return self::usesTrait($fqcn, self::CONTROLLER_TRAIT);
    }

    /**
     * Whether the package's controller decorator would actually run this action's `rules()`/
     * `authorize()` for the dispatched method — i.e. whether documenting them reflects runtime.
     * Mirrors `ControllerDecorator::shouldValidateRequest()`: validation runs only for a non-explicit
     * dispatched method (`asController`/`handle`/`__invoke`, so an explicitly-registered
     * `[Action::class, 'store']` never validates) on an action that does NOT use the `WithAttributes`
     * trait (which opts out of automatic request validation).
     */
    public static function dispatchesValidation(string $fqcn, string $method): bool
    {
        return self::isAction($fqcn)
            && in_array($method, self::DISPATCH_METHODS, true)
            && ! self::usesTrait($fqcn, self::WITH_ATTRIBUTES_TRAIT);
    }

    /**
     * Whether $fqcn uses $trait, walking its own traits + parents' + traits-used-by-traits (so the
     * umbrella `AsAction` trait — which uses `AsController` — is seen) via PHP built-ins only.
     */
    private static function usesTrait(string $fqcn, string $trait): bool
    {
        if (! class_exists($fqcn)) {
            return false;
        }

        $traits = [];
        foreach (array_merge([$fqcn], class_parents($fqcn) ?: []) as $class) {
            self::collectTraits($class, $traits);
        }

        return isset($traits[$trait]);
    }

    /**
     * @param  array<string, string>  $acc
     */
    private static function collectTraits(string $class, array &$acc): void
    {
        foreach (class_uses($class) ?: [] as $trait) {
            if (! isset($acc[$trait])) {
                $acc[$trait] = $trait;
                self::collectTraits($trait, $acc);
            }
        }
    }

    /**
     * The method whose RETURN TYPE is the true 200 wire shape for a JSON client. The package's
     * controller decorator, when the action defines `jsonResponse()` and the client expects JSON,
     * returns `jsonResponse($response, $request)` instead of the dispatched method's value
     * (`ControllerDecorator::__invoke()`). Docuccino documents the JSON path, so the success body must
     * be analysed from `jsonResponse()` — not from the resolved `handle()`/`asController()` whose value
     * the decorator has already transformed. Returns an {@see ActionRef} pointing at `jsonResponse()`
     * when the action defines it, else null (leave the dispatched method's return analysis in place).
     *
     * This mirrors the routing layer's route-method probe (Docuccino\Laravel\Routing\LaravelActionRouteMethod)
     * — reflection-time knowledge of how the route really responds — so it is applied regardless of
     * whether the route dispatches invokably or through an explicitly-registered method (the decorator
     * wraps both).
     */
    public static function responseAnalysisRef(ActionRef $dispatched): ?ActionRef
    {
        $class = $dispatched->class;
        if ($class === null || ! self::isAction($class) || ! method_exists($class, 'jsonResponse')) {
            return null;
        }

        $method = new ReflectionMethod($class, 'jsonResponse');

        return new ActionRef(
            file: (string) $method->getFileName(),
            class: $class,
            method: 'jsonResponse',
            line: (int) $method->getStartLine(),
        );
    }

    /**
     * Whether the action defines `htmlResponse()` — the decorator returns its value for non-JSON
     * clients (`ControllerDecorator::__invoke()`), so the endpoint additionally serves `text/html`.
     * Docuccino records that as a content-type note rather than trying to type an HTML body as JSON.
     */
    public static function definesHtmlResponse(?string $fqcn): bool
    {
        return $fqcn !== null && self::isAction($fqcn) && method_exists($fqcn, 'htmlResponse');
    }
}
