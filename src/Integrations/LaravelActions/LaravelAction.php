<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Laravel\Support\LaravelActionHooks;
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
    /**
     * The trait the integration activates on, spelled out because an integration imports no package yet has
     * to name the one it targets; it is {@see LaravelActionHooks::CONTROLLER_TRAIT}, which a test holds it to.
     */
    public const CONTROLLER_TRAIT = 'Lorisleiva\\Actions\\Concerns\\AsController';

    /**
     * Record the dispatched action's declaration hierarchy as a fragment dependency. Every question
     * below is answered by inheritance — a parent or a trait decides whether this endpoint validates,
     * throws a 403, redirects its success body or serves HTML, without the action class itself moving
     * ({@see DeclarationFiles}). Called before any decline, so "nothing to document here" goes stale
     * with the file that decided it.
     */
    public static function dependsOnDeclaration(RouteContext $context): void
    {
        $context->recordDependencyFiles(DeclarationFiles::of($context->actionRef->class));
    }

    public static function isAction(string $fqcn): bool
    {
        return LaravelActionHooks::isAction($fqcn);
    }

    /** {@see LaravelActionHooks::dispatchesValidation()}: documenting `rules()` elsewhere would misreport runtime. */
    public static function dispatchesValidation(string $fqcn, string $method): bool
    {
        return LaravelActionHooks::dispatchesValidation($fqcn, $method);
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
