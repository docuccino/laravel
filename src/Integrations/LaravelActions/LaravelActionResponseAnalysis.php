<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

use Docuccino\Core\Extensions\Context\ResponseAnalysisRedirect;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ResponseAnalysisTarget;

/**
 * The gated {@see ResponseAnalysisTarget} contributed by the laravel-actions integration: when the
 * action defines `jsonResponse()`, the package's controller decorator returns THAT method's value for
 * JSON clients, so its return type is the real 200 wire shape ({@see LaravelAction::responseAnalysisRef()}).
 * Contributed only when `laravel_actions` is enabled, so a disabled integration never redirects the
 * success-body analysis; the redirect carries the honest provenance producer so the inferred body is
 * attributed to `integration:laravel-actions`, not plain inference.
 */
final class LaravelActionResponseAnalysis implements ResponseAnalysisTarget
{
    public const PRODUCER = 'integration:laravel-actions';

    public function resolve(RouteContext $context): ?ResponseAnalysisRedirect
    {
        $ref = LaravelAction::responseAnalysisRef($context->actionRef);

        return $ref === null ? null : new ResponseAnalysisRedirect($ref, self::PRODUCER);
    }
}
