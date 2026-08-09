<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Attributes\Unauthenticated;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Support\AuthMiddlewareDetector;

/**
 * The security layer (design §Auth detection). `#[Unauthenticated]` marks an operation explicitly
 * public by clearing its requirement (an empty `security: []` overrides any document-level
 * default). Otherwise auto-detection inspects the route's middleware: a match against the
 * configured `auto_detect_middleware` pattern applies the document's default requirement
 * (`security.default`) at the integration layer, so attributes/config can still override it.
 *
 * The scheme catalogue (`components.securitySchemes`) and any document-wide requirement are emitted
 * by the assembler from config; this extension only decides the per-operation requirement.
 */
final class SecurityExtension implements OperationExtension
{
    public function phase(): OperationPhase
    {
        return OperationPhase::Security;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if ($context->attributes->has(Unauthenticated::class)) {
            $operation->setSecurity([], Contribution::attribute($context->actionSource()));

            return;
        }

        $requirement = $context->document->defaultSecurity();
        if ($requirement !== null && $requirement !== [] && AuthMiddlewareDetector::matches($context)) {
            $operation->setSecurity($requirement, Contribution::integration('security'));
        }
    }
}
