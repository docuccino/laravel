<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Examples\SharedRecordingLedger;

/**
 * Whether this process is one worker of a parallel test run, and which run that is.
 *
 * Paratest — which is what `pest --parallel` runs — splits the suite across worker processes and sets
 * `PARATEST` in each of them. Two things here care, and they care in opposite directions.
 *
 * COVERAGE cannot be answered here at all. A worker sees the operations ITS share of the suite
 * exercised and no others, and no worker can know when the others have finished, so a verdict taken
 * from inside one is a guess wearing a number. Merging per-worker files does not fix that — it fixes
 * the data, not the timing — so the coverage assertions refuse rather than name operations the suite
 * exercised perfectly well.
 *
 * RECORDING is per-operation, and a worker writing one needs nothing from any other worker except that
 * they take turns. It merges under a lock instead of refusing ({@see SharedRecordingLedger}),
 * which needs {@see runKey()}: the one thing every worker of a run agrees on and no two runs share.
 */
final class ParallelRun
{
    /** Paratest's own marker, set in every worker it spawns. */
    private const string MARKER = 'PARATEST';

    public static function active(): bool
    {
        return getenv(self::MARKER) !== false;
    }

    /** Which worker this is, for a message that tells the reader what they are looking at. */
    public static function worker(): ?string
    {
        foreach (['UNIQUE_TEST_TOKEN', 'TEST_TOKEN'] as $variable) {
            $token = getenv($variable);

            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        return null;
    }

    /**
     * What every worker of THIS run shares and no later run repeats: the process that spawned them.
     *
     * The runner's own tokens are the opposite of what is wanted — they are per worker, on purpose —
     * and nothing it puts in the environment names the run. The parent does, and it is the same parent
     * for every worker of one run because the runner spawns them itself.
     *
     * Null where the platform cannot say, which is a platform where recording refuses. Guessing would
     * either merge two runs' recordings or split one run's, and both publish an example the suite did
     * not choose.
     */
    public static function runKey(): ?string
    {
        if (! function_exists('posix_getppid')) {
            return null;
        }

        $parent = posix_getppid();

        return $parent > 0 ? 'run-'.$parent : null;
    }
}
