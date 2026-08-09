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
 * The security layer (design §Auth detection). `#[Unauthenticated]` marks an operation public by
 * clearing its requirement — an empty `security: []` beats any document-level default. Otherwise a
 * middleware match against `auto_detect_middleware` applies the document's `security.default` at the
 * integration layer, leaving attributes and config free to override.
 *
 * Only the per-operation requirement is decided here; the scheme catalogue and any document-wide
 * requirement come from the assembler.
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
