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
 * Documents the `403` an action's `authorize()` produces. The package's controller decorator runs
 * `authorize()` during request validation and throws `AuthorizationException` when it returns false —
 * from the validation pipeline, not the analysed `handle()` body, so the engine's throw analysis never
 * sees it. This puts a synthetic `AuthorizationException` through the same exception→response chain as
 * every other error, so the 403 body matches the document's error style. Skipped when
 * `error_responses => 'none'`.
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
            // The synthetic exception has no recovered throw site, so anchor the 403 to the action —
            // authorize() is where it really comes from, and a source beats none.
            $this->applier->apply($operation, $mapped->draft, $mapped->mapper->producer(), $context->actionSource());
        }
    }

    private function definesAuthorize(RouteContext $context): bool
    {
        $class = $context->actionRef->class;
        // Only document the 403 where the package would really run authorize(): an explicitly-registered
        // method or a WithAttributes action never validates, so nothing is thrown there.
        if ($class === null || ! LaravelAction::dispatchesValidation($class, $context->actionRef->method) || ! class_exists($class)) {
            return false;
        }

        return (new ReflectionClass($class))->hasMethod('authorize');
    }
}
