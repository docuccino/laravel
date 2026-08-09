<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;

/**
 * One info diagnostic per callback that couldn't fold a JSON response, naming the callback, the count and the
 * first few exception types. Reads the {@see HandlerDeferralLog} the tier fills, runs once per build, and
 * never mutates the document.
 */
final class HandlerDeferralSummaryTransformer implements DocumentTransformer
{
    /** How many exception FQCNs to name before summarising the rest as "(and N more)". */
    private const PREVIEW = 3;

    public function __construct(private readonly HandlerDeferralLog $log) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        foreach ($this->log->summaries() as $summary) {
            $exceptions = $summary['exceptions'];
            $count = count($exceptions);
            $preview = implode(', ', array_slice($exceptions, 0, self::PREVIEW));
            $more = $count > self::PREVIEW ? sprintf(' (and %d more)', $count - self::PREVIEW) : '';

            $context->report(new Diagnostic(
                severity: Severity::Info,
                code: 'inferred-handler.too-dynamic',
                message: sprintf(
                    'The exception handler %s could not fold a JSON response for %d exception type(s): %s%s; those responses defer to the next error tier.',
                    $summary['callback'],
                    $count,
                    $preview,
                    $more,
                ),
                help: 'Return a JSON response with a constant status (or a helper that does) from the handler, or document these responses with an attribute, so Docuccino can recover their shape.',
            ));
        }
    }
}
