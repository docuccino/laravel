<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Laravel\Support\MiddlewareClasses;
use Illuminate\Routing\Middleware\ValidateSignature;

/**
 * The `signature` (required) and `expires` (optional — the framework checks it only when present) query
 * parameters a route behind the signed-URL middleware refuses a request without. Recognised by the same
 * {@see MiddlewareClasses} question the implicit 403 asks, so a route never publishes that error without
 * what avoids it; written at the integration layer, so the parameter attributes still win.
 */
final class SignedRouteParametersExtension implements OperationExtension
{
    private const SIGNATURE = 'Signature of the signed link this request is made from. Send it, and the link\'s other query parameters, as the link carries them.';

    private const EXPIRES = 'Unix timestamp after which the signed link is no longer accepted. Carried only by links that expire.';

    public function __construct(private readonly MiddlewareClasses $middleware) {}

    public function phase(): OperationPhase
    {
        return OperationPhase::Parameters;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        if (! $this->isSigned($context)) {
            return;
        }

        $contribution = Contribution::integration('signed-url', $context->actionSource());

        $signature = $operation->parameter('query', 'signature');
        $signature->setRequired(true, $contribution);
        $signature->setDescription(self::SIGNATURE, $contribution);
        $signature->schema()->set('type', 'string', $contribution);

        $expires = $operation->parameter('query', 'expires');
        $expires->setRequired(false, $contribution);
        $expires->setDescription(self::EXPIRES, $contribution);
        $expires->schema()->set('type', 'integer', $contribution);
    }

    private function isSigned(RouteContext $context): bool
    {
        foreach ($context->route->middleware as $middleware) {
            if ($this->middleware->runs($context, $middleware, ValidateSignature::class)) {
                return true;
            }
        }

        return false;
    }
}
