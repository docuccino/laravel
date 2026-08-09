<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Contributes the booted app's registered render-callback set (exception FQCN + source location, in
 * registration order) to the environment digest (design §10, A4). Adding/removing/replacing a
 * `$exceptions->render(…)` handler must re-document the inferred-handler error tier — the add-a-handler
 * asymmetry the per-file dependency hashes alone miss. Always-on (the inferred-handler tier ships as a
 * built-in), so this segment is present for every build. Defensive: an unresolvable handler contributes
 * the empty string.
 */
final class RenderCallbackDigestContributor implements EnvironmentDigestContributor
{
    public function __construct(private readonly ExceptionHandler $handler) {}

    public function digest(): string
    {
        try {
            $reflector = new HandlerReflector($this->handler);

            $records = [];
            foreach ($reflector->renderCallbacks() as $callback) {
                // file+line is the callback's source location — for a method-backed callback (invokable
                // renderer, `[$obj, 'method']`, first-class callable) that is the invokable class file +
                // the method's declaration line, so editing the renderer re-documents the tier; the method
                // name captures a re-bind to a different method within the same file.
                $records[] = $callback->exceptionType.'@'.$callback->file.':'.$callback->line
                    .($callback->method !== null ? '#'.$callback->method : '');
            }

            // A registered-but-unanalysable callback still shifts the tier's shape (it now reports a skip),
            // so fold its label in too — otherwise adding/removing one would not invalidate the fragments.
            return 'render:'.implode(',', $records).'|skipped:'.implode(',', $reflector->skipped());
        } catch (Throwable) {
            return '';
        }
    }
}
