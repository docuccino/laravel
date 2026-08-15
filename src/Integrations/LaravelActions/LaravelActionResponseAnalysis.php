<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\LaravelActions;

use Docuccino\Core\Extensions\Context\ResponseAnalysisRedirect;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\ResponseAnalysisTarget;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;

/**
 * Redirects success-body analysis to `jsonResponse()` when the action defines one — see
 * {@see LaravelAction::responseAnalysisRef()}. Contributed only while the integration is enabled, so a
 * disabled one never touches the 200 body, and the redirect names its own producer so the inferred body is
 * attributed to the integration rather than to plain inference.
 */
final class LaravelActionResponseAnalysis implements ResponseAnalysisTarget
{
    public const PRODUCER = 'integration:laravel-actions';

    public function resolve(RouteContext $context): ?ResponseAnalysisRedirect
    {
        // Whether a `jsonResponse()` exists to redirect to is answered by inheritance, and the answer
        // decides which method's return type becomes the 200 body.
        $context->recordDependencyFiles(DeclarationFiles::of($context->actionRef->class));

        $ref = LaravelAction::responseAnalysisRef($context->actionRef);

        return $ref === null ? null : new ResponseAnalysisRedirect($ref, self::PRODUCER);
    }
}
