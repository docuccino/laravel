<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Laravel\Support\TerminalText;
use Illuminate\Console\Command;

/**
 * The section heading the commands print: a bold title over a rule exactly as wide as it, and an
 * optional grey line under. Titles carry route signatures and document keys, so they go out through
 * {@see TerminalText} whether or not a given caller's happens to be a literal.
 *
 * @mixin Command
 */
trait PrintsSections
{
    protected function section(string $title, ?string $meta = null): void
    {
        $this->newLine();
        $this->line(sprintf('<options=bold>%s</>', TerminalText::of($title)));
        $this->line(sprintf('<fg=gray>%s</>', str_repeat('─', mb_strlen($title))));

        if ($meta !== null) {
            $this->line(sprintf('<fg=gray>%s</>', TerminalText::of($meta)));
        }
    }
}
