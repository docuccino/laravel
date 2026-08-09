<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Illuminate\Console\Command;

/**
 * Honours the `docuccino.enabled` master off-switch on the artisan commands (security M3): a
 * disabled installation exits early with a clear message rather than silently building or serving
 * documentation.
 *
 * @mixin Command
 */
trait GuardsEnabled
{
    /** True (after printing a message) when Docuccino is disabled and the command should stop. */
    protected function abortIfDisabled(): bool
    {
        if (config('docuccino.enabled', true) === false) {
            $this->error('Docuccino is disabled (set docuccino.enabled = true to run this command).');

            return true;
        }

        return false;
    }
}
