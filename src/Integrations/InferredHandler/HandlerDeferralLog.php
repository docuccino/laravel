<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\InferredHandler;

use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteNotes;
use Docuccino\Core\Extensions\Contracts\RouteNoteCollector;

/**
 * Per-document collector of inferred-handler deferrals (design §6), keyed by callback rather than by
 * (route × exception) — the latter produces hundreds of near-identical diagnostics on a large app.
 * {@see HandlerDeferralSummaryTransformer} then emits one summary per callback.
 *
 * The tier never calls {@see collect()}: it records the deferral on the route's {@see RouteNotes}, the
 * pipeline drains those into here, and a route that came back from the fragment cache drains exactly the
 * same note — which is why a warm build reports what a cold one reports. Container-`scoped`, so the tier
 * and the transformer share an instance, and the pipeline empties it per document.
 *
 * Framework delegation (a `return null`/void arm) is not recorded: it's expected, not a fold failure, and
 * the next tier handles those exception types.
 */
final class HandlerDeferralLog implements RouteNoteCollector
{
    /** The {@see RouteNotes} channel the tier writes its deferrals to. */
    public const CHANNEL = 'inferred-handler.deferral';

    /**
     * Note that a callback's JSON body could not be read for one exception type. Written wherever the
     * document ends up without the shape that callback renders — the tier declining outright, and the
     * widened answer that keeps the media type alone ({@see HandlerResponseBuilder}) — so the two spell
     * one note rather than two.
     */
    public static function record(RouteContext $context, string $renderer, string $exceptionFqcn): void
    {
        $context->notes()->record(self::CHANNEL, $renderer, $exceptionFqcn);
    }

    /** @var array<string, list<string>> callback target ⇒ deduped exception FQCNs it could not fold */
    private array $entries = [];

    public function channel(): string
    {
        return self::CHANNEL;
    }

    public function forget(): void
    {
        $this->entries = [];
    }

    /**
     * The one way in — a route's notes, drained by the pipeline. Repeats across routes are expected: one
     * render callback answers for every route that throws through it.
     *
     * @param  list<string>  $values
     */
    public function collect(string $key, array $values): void
    {
        $exceptions = $this->entries[$key] ?? [];
        foreach ($values as $value) {
            if (! in_array($value, $exceptions, true)) {
                $exceptions[] = $value;
            }
        }

        $this->entries[$key] = $exceptions;
    }

    /**
     * One entry per deferring callback. Callbacks are sorted, and so are the exceptions under each, so
     * what the summary says is a function of which types could not be folded and never of the order the
     * routes that threw them were met.
     *
     * @return list<array{callback: string, exceptions: list<string>}>
     */
    public function summaries(): array
    {
        $callbacks = array_keys($this->entries);
        sort($callbacks);

        return array_map(
            function (string $callback): array {
                $exceptions = $this->entries[$callback];
                sort($exceptions);

                return ['callback' => $callback, 'exceptions' => $exceptions];
            },
            $callbacks,
        );
    }
}
