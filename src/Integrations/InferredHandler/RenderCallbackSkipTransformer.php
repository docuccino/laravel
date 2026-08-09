<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;

/**
 * Kills the inferred-handler tier's silence (design §6): a render callback registered on the handler
 * but not analysable — no parameters, a builtin-typed first parameter, or a bound free function with no
 * owning class — used to be dropped without a word, so the tier looked absent when it had simply skipped
 * a handler it could not read. This whole-document transformer reports one info diagnostic per skipped
 * callback (it never mutates the document), naming the callable so the omission is explained rather than
 * silent. Runs once per build regardless of routes, so a skip surfaces even when nothing the tier could
 * document remains.
 */
final class RenderCallbackSkipTransformer implements DocumentTransformer
{
    public function __construct(private readonly HandlerReflector $reflector) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        foreach ($this->reflector->skipped() as $callable) {
            $context->report(new Diagnostic(
                severity: Severity::Info,
                code: 'inferred-handler.render-callback-skipped',
                message: sprintf(
                    'The render callback %s could not be analysed and was skipped; its error responses fall through to the next tier.',
                    $callable,
                ),
                help: 'Register the renderer as an invokable object, an [$object, \'method\'] pair, or a closure typed to the exception it handles so Docuccino can read its response shape.',
            ));
        }
    }
}
