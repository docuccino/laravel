<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Extensions\Contracts\RouteNoteCollector;

/**
 * A {@see RouteNoteCollector} that keeps what it was handed, so a test can assert a note really reached
 * the aggregate rather than only that the document looks unchanged.
 *
 * `forget()` empties it completely on purpose: the pipeline calls it before a document's first fragment
 * and BEFORE it digests the resolved extensions into the fragment-cache signature, so a collector whose
 * state survived would key two builds differently and turn a warm hit into a cold one — a test that then
 * proves nothing about a warm restore.
 */
final class CollectedRouteNotes implements RouteNoteCollector
{
    /** @var array<string, list<string>> key ⇒ the deduped values the fragments carried for it */
    private array $collected = [];

    public function __construct(private readonly string $channel) {}

    public function channel(): string
    {
        return $this->channel;
    }

    public function forget(): void
    {
        $this->collected = [];
    }

    /**
     * @param  list<string>  $values
     */
    public function collect(string $key, array $values): void
    {
        $existing = $this->collected[$key] ?? [];
        foreach ($values as $value) {
            if (! in_array($value, $existing, true)) {
                $existing[] = $value;
            }
        }

        $this->collected[$key] = $existing;
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return $this->collected;
    }
}
