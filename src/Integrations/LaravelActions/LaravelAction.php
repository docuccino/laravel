<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

use Docuccino\Core\Inference\ActionRef;
use ReflectionMethod;

/**
 * Recognises a `lorisleiva/laravel-actions` action (it carries `AsController`, directly or via the
 * umbrella `AsAction` trait) and answers the integration's per-route questions: would the package run
 * `rules()`/`authorize()` here, does it redirect the success body through `jsonResponse()`, does it define
 * `htmlResponse()`. Every check is guarded by the trait's presence, so this is inert without the package.
 *
 * The route-identity remap (which method an invokable route dispatches) lives in the routing layer
 * instead — Docuccino\Laravel\Routing\LaravelActionRouteMethod — so it runs even with this integration off.
 */
final class LaravelAction
{
    public const CONTROLLER_TRAIT = 'Lorisleiva\\Actions\\Concerns\\AsController';

    /** The trait that opts an action out of the package's automatic request validation. */
    public const WITH_ATTRIBUTES_TRAIT = 'Lorisleiva\\Actions\\Concerns\\WithAttributes';

    /** The methods the package treats as non-explicit (it remaps invokable routes onto these). */
    private const DISPATCH_METHODS = ['asController', 'handle', '__invoke'];

    public static function isAction(string $fqcn): bool
    {
        if (! trait_exists(self::CONTROLLER_TRAIT)) {
            return false;
        }

        return self::usesTrait($fqcn, self::CONTROLLER_TRAIT);
    }

    /**
     * Mirrors `ControllerDecorator::shouldValidateRequest()`: the package only validates for a
     * non-explicit dispatched method (so an explicitly-registered `[Action::class, 'store']` never does)
     * on an action without `WithAttributes`. Documenting `rules()` elsewhere would misreport runtime.
     */
    public static function dispatchesValidation(string $fqcn, string $method): bool
    {
        return self::isAction($fqcn)
            && in_array($method, self::DISPATCH_METHODS, true)
            && ! self::usesTrait($fqcn, self::WITH_ATTRIBUTES_TRAIT);
    }

    /**
     * Walks own traits + parents' + traits-used-by-traits, so `AsAction` (which uses `AsController`)
     * counts. Built-ins only, no reflection.
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
     * The method whose return type is the real 200 wire shape for a JSON client. When the action defines
     * `jsonResponse()`, `ControllerDecorator::__invoke()` returns that instead of the dispatched method's
     * value, so the success body must be analysed there — `handle()`'s value has already been transformed.
     * Null leaves the dispatched method's analysis alone. Applies to invokable and explicitly-registered
     * routes alike; the decorator wraps both.
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
     * The decorator returns `htmlResponse()`'s value for non-JSON clients, so the endpoint also serves
     * `text/html`. Recorded as a content-type note; we don't try to type an HTML body as JSON.
     */
    public static function definesHtmlResponse(?string $fqcn): bool
    {
        return $fqcn !== null && self::isAction($fqcn) && method_exists($fqcn, 'htmlResponse');
    }
}
