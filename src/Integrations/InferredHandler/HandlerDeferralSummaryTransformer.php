<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Provenance\MessagePaths;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;

/**
 * One info diagnostic per callback that couldn't fold a JSON response, naming the callback, the count and the
 * first few exception types. Reads the {@see HandlerDeferralLog} the pipeline fills from each route's notes,
 * runs once per document, and never mutates the document.
 */
final class HandlerDeferralSummaryTransformer implements DocumentTransformer
{
    /** How many exception FQCNs to name before summarising the rest as "(and N more)". */
    private const PREVIEW = 3;

    public function __construct(
        private readonly HandlerDeferralLog $log,
        // A genuine closure render callback has no class, so the label the log keys by is the closure's
        // FILE — see {@see MessagePaths} for why that may not be published as it stands. Without a project
        // root the ladder still runs, so the fallback degrades rather than naming the machine.
        private readonly MessagePaths $messagePaths = new MessagePaths(new RootRelativeSourcePathResolver('')),
    ) {}

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
                    // Our words are composed around the scrubbed label, never through it: the exception
                    // FQCNs beside it are namespaces, and the count is a number.
                    $this->messagePaths->relative($summary['callback']),
                    $count,
                    $preview,
                    $more,
                ),
                help: 'Return a JsonResponse from the arm — `response()->json(…)`, not a plain `response()`, a view or a redirect — and give it a literal integer status: `404`, not `$e->getCode()` or a ternary. Either a status that folds or a body that folds is enough, and that is what settles this. Naming these responses with #[Response] corrects the document instead, and this notice keeps naming the callback.',
            ));
        }
    }
}
