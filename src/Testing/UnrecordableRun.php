<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Examples\UnlockableRecording;
use RuntimeException;

/**
 * The response recorder was asked to record a run it cannot record. Every case is wiring, and every
 * message names the line to change.
 */
final class UnrecordableRun extends RuntimeException
{
    /**
     * Workers merge their recordings through a lock, which has to know which RUN they belong to —
     * {@see ParallelRun::runKey()} — and on a platform that cannot say, refusing is the only answer
     * that keeps the file a function of the suite.
     */
    public static function indeterminate(?string $worker): self
    {
        return new self(sprintf(
            "Response recordings cannot be written from inside a parallel test run on this platform%s.\n".
            "Workers share their recordings through a lock, and this PHP cannot tell which run a worker\n".
            "belongs to — there is no ext-posix to ask — so two runs' recordings would be merged into one\n".
            'file. Record in a single-process job (drop --parallel); every other contract assertion is unaffected.',
            $worker === null ? '' : ' (worker '.$worker.')',
        ));
    }

    /** The lock itself failed, so nothing was written: a half-merged recording is worse than none. */
    public static function unlockable(UnlockableRecording $failure): self
    {
        return new self(sprintf(
            "Response recordings cannot be written safely here: %s\n".
            "Workers take turns through that lock; without it each would write the whole file back and the\n".
            "published example would be whichever finished last, so nothing was written at all.\n".
            'Record in a single-process job (drop --parallel), or point TMPDIR at a directory of your own on a filesystem that locks.',
            $failure->getMessage(),
        ), previous: $failure);
    }

    public static function unconfigured(string $document): self
    {
        return new self(sprintf(
            "There is nowhere to write response recordings for the \"%s\" document.\n".
            "Say where they live in config/docuccino.php:\n".
            "    'examples' => ['recordings' => 'docs/recordings'],\n".
            "Or name a directory at the call site: ApiContract::record('docs/recordings').",
            $document,
        ));
    }

    /** A name is a call-site literal, so it is answered where it was written rather than at build time. */
    public static function badName(string $name): self
    {
        return new self(sprintf(
            "\"%s\" is not a name a recorded example can carry.\n".
            "The name becomes a key in the document's `examples` map, which a generated client reads, so it\n".
            "starts with a letter or a digit and carries letters, digits, dots, dashes and underscores up to\n".
            '64 of them. Rename it at the call site: ->assertValidResponse(recordAs: \'empty-cart\').',
            $name,
        ));
    }
}
