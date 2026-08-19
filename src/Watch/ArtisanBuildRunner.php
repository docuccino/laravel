<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Watch;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs one rebuild as `docuccino:export` in a FRESH PHP process.
 *
 * That is not an optimisation, it is the only way a watch loop can be right. The watching process
 * has already loaded the controllers, form requests and resources it documented, and PHP never
 * un-loads a class: an in-process rebuild would keep reflecting the code as it was when the loop
 * started, and would not see a route added since at all. `queue:listen` re-execs for the same
 * reason. What makes it cheap is that the fragment cache is on disk, so the new process picks up
 * every operation the last one built and re-analyses only what changed — which is why the run
 * carries {@see FRAGMENT_CACHE} for the build to read.
 *
 * @internal
 */
final readonly class ArtisanBuildRunner implements BuildRunner
{
    /** Turns the fragment cache on for the builds a watch session drives, whatever config defaults to. */
    public const string FRAGMENT_CACHE = 'DOCUCCINO_FRAGMENT_CACHE';

    /**
     * Long enough for a cold analysis of a large application, short enough that a build which has
     * wedged ends. A watch session outlives its builds, so a hung one has to be given up on rather
     * than waited for.
     */
    public const int TIMEOUT = 900;

    /** What a build that never reported an exit code of its own is worth to the caller. */
    private const int FAILED = 1;

    public function __construct(
        private string $artisan,
        private string $php = PHP_BINARY,
        private int $timeout = self::TIMEOUT,
    ) {}

    public function build(OutputInterface $output, ?string $document, ?string $memoryLimit): int
    {
        try {
            $result = Process::env([self::FRAGMENT_CACHE => '1'])
                ->timeout($this->timeout)
                // Both streams go to the one output the session is already writing to, as they did
                // when the child inherited the terminal, and each chunk goes as it arrives so a
                // twenty-second build isn't twenty seconds of silence.
                ->run(
                    $this->command($document, $memoryLimit, $output->isDecorated()),
                    static function (string $stream, string $chunk) use ($output): void {
                        // Raw: the bytes are the child's own formatting already, so anything else
                        // would read its escapes as markup or strip the colour we just asked for.
                        $output->write($chunk, false, OutputInterface::OUTPUT_RAW);
                    },
                );

            return $result->exitCode() ?? self::FAILED;
        } catch (ProcessTimedOutException $e) {
            $output->writeln(sprintf(
                '<fg=red>The build did not finish within %d seconds and was stopped. Watching for the next change.</>',
                $this->timeout,
            ));

            return $e->result->exitCode() ?? self::FAILED;
        }
    }

    /**
     * The argv the rebuild is spawned with. Nothing is escaped and nothing needs to be: array-form
     * Process execs PHP directly with no shell, so a project path with a space or an `&` in it stays
     * one argument on every platform. `--ansi` because the child's stdout is a pipe here rather than
     * the terminal, and Symfony reads that as "don't colour" — TTY mode would say the same thing but
     * only where there is one to hand out, which on Windows there never is.
     *
     * @return list<string>
     */
    public function command(?string $document, ?string $memoryLimit, bool $ansi = false): array
    {
        $parts = [$this->php, $this->artisan, 'docuccino:export'];

        if ($document !== null && $document !== '') {
            $parts[] = $document;
        }

        if ($memoryLimit !== null && $memoryLimit !== '') {
            $parts[] = '--memory-limit='.$memoryLimit;
        }

        if ($ansi) {
            $parts[] = '--ansi';
        }

        return $parts;
    }
}
