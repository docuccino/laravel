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
 * One warning per callback that couldn't fold a JSON response, naming the callback, the count and the first
 * few exception types. Reads the {@see HandlerDeferralLog} the pipeline fills from each route's notes, runs
 * once per document, and never mutates the document.
 *
 * A WARNING because of what it predicts, not because of how wrong the document is: where this fires and
 * the tier declines, the application's own renderer has demonstrably replaced the framework's body, so the
 * error is published with no `content` at all — and a contract test on a response that really does return
 * bytes fails against exactly that. A diagnostic whose consequence is a red build is not a notice.
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
                severity: Severity::Warning,
                code: 'inferred-handler.too-dynamic',
                message: sprintf(
                    // Not "defers to the next tier": where the media type folded and the body did not, the
                    // tier answers with the media type alone, so what every entry here has in common is
                    // the shape being missing rather than what the chain did about it.
                    'The exception handler %s could not fold a JSON response for %d exception type(s): %s%s; those errors are documented without the shape it renders.',
                    // Our words are composed around the scrubbed label, never through it: the exception
                    // FQCNs beside it are namespaces, and the count is a number.
                    $this->messagePaths->relative($summary['callback']),
                    $count,
                    $preview,
                    $more,
                ),
                help: 'Two remedies. Make the arm readable: return a JsonResponse — `response()->json(…)`, not a plain `response()`, a view or a redirect — with the payload written at that call site, and a literal integer status (`404`, not `$e->getCode()` or a ternary). The payload is what settles this: where only the status or the `Content-Type` folded, the response publishes that much and no shape, and this warning stands. Or state the response yourself with #[Response(status: 404, type: ErrorPayload::class)] on the action, which publishes the shape — it corrects the document without silencing this warning, and the warning keeps naming the callback.',
            ));
        }
    }
}
