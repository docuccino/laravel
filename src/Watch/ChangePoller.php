<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Watch;

/**
 * Waits for the watched set to move, by re-reading it on an interval.
 *
 * Polling rather than an OS watch API: inotify/kqueue need an extension that is not there by
 * default on any of the platforms this runs on, and stat-ing a few hundred files a second costs
 * nothing measurable next to the build it guards. It is also the same reading every time, which a
 * watch mode wants — the alternative degrades to polling on the platforms it can't cover anyway.
 *
 * The wait is sliced so an interrupt is answered within a fifth of a second however long the
 * interval is, rather than after the sleep it landed in.
 *
 * @internal
 */
final readonly class ChangePoller
{
    private const int SLICE_MICROSECONDS = 200_000;

    public function __construct(
        private WatchSet $watched,
        private float $interval,
    ) {}

    /**
     * The files that moved, or an empty list when `$stopped` asked us to give up first.
     *
     * @param  list<string>  $roots
     * @param  callable(): bool  $stopped
     * @return list<string>
     */
    public function await(array $roots, callable $stopped): array
    {
        $before = $this->watched->snapshot($roots);

        while (! $stopped()) {
            $this->sleep($stopped);

            if ($stopped()) {
                break;
            }

            $changed = WatchSet::changed($before, $this->watched->snapshot($roots));
            if ($changed !== []) {
                return $changed;
            }
        }

        return [];
    }

    /**
     * @param  callable(): bool  $stopped
     */
    private function sleep(callable $stopped): void
    {
        $remaining = (int) round($this->interval * 1_000_000);

        while ($remaining > 0 && ! $stopped()) {
            $slice = min($remaining, self::SLICE_MICROSECONDS);
            usleep($slice);
            $remaining -= $slice;
        }
    }
}
