<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Illuminate\Support\Facades\Process;

/**
 * `git show <ref>:<path>` — how both `docuccino:diff --against` and the contract assertions read a
 * committed artifact as of a branch or tag rather than out of the working tree.
 *
 * Array-form Process runs git directly with no shell, so nothing is word-split or expanded, and a ref
 * or path git would read as an option is refused rather than handed over: `--upload-pack=…` as a ref
 * names a command, not a revision.
 *
 * @internal
 */
final class GitShow
{
    /**
     * The blob's contents, or null with the reason — git's own stderr, or why the operands were
     * refused. Both halves come from outside the terminal, so anything printing one owes it
     * {@see TerminalText}.
     *
     * @return array{0: string|null, 1: string}
     */
    public static function read(string $ref, string $path): array
    {
        if (str_starts_with($ref, '-') || str_starts_with($path, '-')) {
            return [null, 'the git ref and path must not start with "-"'];
        }

        $result = Process::run(['git', 'show', $ref.':'.$path]);

        return $result->successful() ? [$result->output(), ''] : [null, trim($result->errorOutput())];
    }
}
