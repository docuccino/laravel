<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Validation\ResponseDraftApplier;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use ReflectionClass;

/**
 * Documents the `403` an action's `authorize()` produces (Phase 5c). When a laravel-actions action
 * defines `authorize()`, the package's controller decorator runs it during request validation and
 * throws `Illuminate\Auth\Access\AuthorizationException` (403) when it returns false. That throw is
 * raised by the framework's validation pipeline, not the analysed `handle()` body, so the engine's
 * throw analysis never sees it — this extension reintroduces it by running a synthetic
 * `AuthorizationException` through the SAME resolved exception→response chain the rest of the error
 * responses use, so the 403 body matches the document's error style (framework defaults or the
 * Problem Details preset). Skipped when `error_responses => 'none'`.
 */
final class ActionAuthorizeResponsesExtension implements OperationExtension
{
    private const AUTHORIZATION_EXCEPTION = 'Illuminate\\Auth\\Access\\AuthorizationException';

    public function __construct(
        private readonly ResponseDraftApplier $applier = new ResponseDraftApplier,
    ) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Errors;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if ($context->document->errorResponses === 'none' || ! $this->definesAuthorize($context)) {
            return;
        }

        $throw = new ThrownException(
            self::AUTHORIZATION_EXCEPTION,
            403,
            [],
            ThrowConfidence::Certain,
            ThrowDisposition::Signal,
        );

        $mapped = $context->mapThrow($throw);
        if ($mapped !== null) {
            // The synthetic AuthorizationException has no recovered throw site; the real one is the
            // action's authorize() gate, so anchor the 403 to the action (arch review PIN 4 — carry a
            // source rather than none).
            $this->applier->apply($operation, $mapped->draft, $mapped->mapper->producer(), $context->actionSource());
        }
    }

    private function definesAuthorize(RouteContext $context): bool
    {
        $class = $context->actionRef->class;
        // Only document the 403 when the package would actually run authorize() for the dispatched
        // method: an explicitly-registered method or a WithAttributes action never validates at
        // runtime, so no AuthorizationException is ever thrown from the validation pipeline there.
        if ($class === null || ! LaravelAction::dispatchesValidation($class, $context->actionRef->method) || ! class_exists($class)) {
            return false;
        }

        return (new ReflectionClass($class))->hasMethod('authorize');
    }
}
