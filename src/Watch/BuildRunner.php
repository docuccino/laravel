<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Watch;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * One rebuild for `docuccino:watch`. An interface so the loop can be driven without spawning
 * anything; {@see ArtisanBuildRunner} is what a real session runs.
 *
 * @internal
 */
interface BuildRunner
{
    /**
     * Run one build, streaming its output as it arrives, and answer its exit code.
     *
     * @param  OutputInterface  $output  where the build writes — the session's own output, so a
     *                                   rebuild reads like the command that started it
     * @param  string|null  $document  the `{document?}` argument as given, or null for every document
     * @param  string|null  $memoryLimit  the `--memory-limit` value as given, or null
     */
    public function build(OutputInterface $output, ?string $document, ?string $memoryLimit): int;
}
