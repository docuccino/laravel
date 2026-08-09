<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

/**
 * Per-build collector of inferred-handler deferrals (design §6), keyed by callback rather than by
 * (route × exception) — the latter produces hundreds of near-identical diagnostics on a large app.
 * {@see HandlerDeferralSummaryTransformer} then emits one summary per callback. Container-`scoped`, so the
 * tier and the transformer share an instance within a build and it resets between builds.
 *
 * Framework delegation (a `return null`/void arm) is not recorded: it's expected, not a fold failure, and
 * the next tier handles those exception types.
 */
final class HandlerDeferralLog
{
    /** @var array<string, list<string>> callback target ⇒ deduped exception FQCNs it could not fold */
    private array $entries = [];

    public function record(string $callback, string $exceptionFqcn): void
    {
        $exceptions = $this->entries[$callback] ?? [];
        if (! in_array($exceptionFqcn, $exceptions, true)) {
            $exceptions[] = $exceptionFqcn;
        }
        $this->entries[$callback] = $exceptions;
    }

    /**
     * One entry per deferring callback, exceptions in first-seen order. Callbacks are sorted so the
     * diagnostics come out deterministically.
     *
     * @return list<array{callback: string, exceptions: list<string>}>
     */
    public function summaries(): array
    {
        $callbacks = array_keys($this->entries);
        sort($callbacks);

        return array_map(
            fn (string $callback): array => ['callback' => $callback, 'exceptions' => $this->entries[$callback]],
            $callbacks,
        );
    }
}
