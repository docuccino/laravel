<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Feeds the registered render-callback set (exception FQCN + source location, registration order) into the
 * environment digest (design §10). Adding, removing or replacing a `$exceptions->render(…)` handler has to
 * re-document the inferred-handler tier, and per-file dependency hashes alone miss the added-a-handler
 * case. An unresolvable handler contributes the empty string.
 */
final class RenderCallbackDigestContributor implements EnvironmentDigestContributor
{
    public function __construct(private readonly ExceptionHandler $handler) {}

    public function digest(): string
    {
        try {
            $reflector = new HandlerReflector($this->handler);

            $parts = ['render'];
            foreach ($reflector->renderCallbacks() as $callback) {
                // For a method-backed callback, file+line is the class file plus the method's declaration
                // line, so editing the renderer re-documents the tier; the method name catches a re-bind to
                // a different method in the same file.
                $parts[] = $callback->exceptionType;
                $parts[] = $callback->file;
                $parts[] = (string) $callback->line;
                $parts[] = $callback->method ?? '';
            }

            // An unanalysable callback still changes the tier's shape (it now reports a skip), so its label
            // goes in too; otherwise adding or removing one wouldn't invalidate the fragments.
            return implode("\0", [...$parts, 'skipped', ...$reflector->skipped()]);
        } catch (Throwable) {
            return '';
        }
    }
}
