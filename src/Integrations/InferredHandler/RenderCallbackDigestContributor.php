<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Extensions\Contracts\EnvironmentDigestContributor;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Feeds the registered render-callback set (exception FQCN + source location, registration order), the
 * `respond()` callback's location and the exception map into the environment digest (design §10). Adding,
 * removing or replacing any of them has to re-document the inferred-handler tier, and per-file dependency
 * hashes alone miss the added-a-handler case. An unresolvable handler contributes the empty string.
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
                $parts[] = $callback->at->file;
                $parts[] = (string) $callback->at->line;
                $parts[] = $callback->at->method ?? '';
            }

            // An unanalysable callback still changes the tier's shape (it now reports a skip), so its label
            // goes in too; otherwise adding or removing one wouldn't invalidate the fragments.
            $parts = [...$parts, 'skipped', ...$reflector->skipped()];

            $respond = $reflector->respondCallback();
            if ($respond !== null) {
                $parts = [...$parts, 'respond', $respond->at->file, (string) $respond->at->line, $respond->at->method ?? ''];
            } elseif ($reflector->respondUnlocated() !== null) {
                $parts = [...$parts, 'respond', (string) $reflector->respondUnlocated()];
            }

            // The exception map decides which exception every other hook is asked about, so adding, removing,
            // re-ordering or re-pointing an entry re-documents every route throwing through it. A class-string
            // target has no file for a dependency hash to watch, so the class itself goes in.
            foreach ($reflector->exceptionMappings() as $mapping) {
                $parts = [...$parts, 'map', $mapping->from, $mapping->target ?? '', $mapping->at->file ?? $mapping->label, (string) ($mapping->at->line ?? 0), $mapping->at->method ?? ''];
            }

            return implode("\0", $parts);
        } catch (Throwable) {
            return '';
        }
    }
}
