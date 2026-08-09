<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

/**
 * Per-build collector of inferred-handler deferrals (design §6). The tier used to emit one
 * `too-dynamic` info diagnostic per (route × thrown exception type) — 656 near-identical lines when run
 * against a large production Laravel app. Instead, each genuine defer (a renderer whose response for a type could not be folded to
 * JSON) is recorded here, keyed by CALLBACK, and {@see HandlerDeferralSummaryTransformer} emits one
 * summary per callback at document build. Container-`scoped` so the tier and the transformer share one
 * instance within a build and it resets between builds.
 *
 * Framework DELEGATION (a `return null` / void arm) is NOT recorded — it is expected behaviour, not a
 * fold failure, and the next tier handles those exception types.
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
     * One entry per callback that deferred, exception FQCNs in first-seen order. Callbacks are sorted
     * for deterministic diagnostic ordering.
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
