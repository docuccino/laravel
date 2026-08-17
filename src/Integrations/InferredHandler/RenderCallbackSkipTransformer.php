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
 * Reports one info diagnostic per render callback the reflector had to skip — no parameters, a
 * builtin-typed first parameter, a bound free function with no owning class — naming the callable so the
 * omission is explained rather than making the tier look absent. Never mutates the document, and runs once
 * per build regardless of routes, so a skip surfaces even when the tier documented nothing.
 */
final class RenderCallbackSkipTransformer implements DocumentTransformer
{
    public function __construct(
        private readonly HandlerReflector $reflector,
        // An anonymous callback has no name but the file it was written in, so the label carries a path —
        // {@see MessagePaths} states what that costs and what it takes to publish it.
        private readonly MessagePaths $messagePaths = new MessagePaths(new RootRelativeSourcePathResolver('')),
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        foreach ($this->reflector->skipped() as $callable) {
            $context->report(new Diagnostic(
                severity: Severity::Info,
                code: 'inferred-handler.render-callback-skipped',
                message: sprintf(
                    'The render callback %s could not be analysed and was skipped; its error responses fall through to the next tier.',
                    $this->messagePaths->relative($callable),
                ),
                help: 'Register the renderer as an invokable object, an [$object, \'method\'] pair, or a closure typed to the exception it handles so Docuccino can read its response shape.',
            ));
        }
    }
}
