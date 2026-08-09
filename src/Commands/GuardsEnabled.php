<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Illuminate\Console\Command;

/**
 * Honours the `docuccino.enabled` master off-switch: a disabled install says so and stops rather than
 * quietly building documentation.
 *
 * @mixin Command
 */
trait GuardsEnabled
{
    /** True — having printed why — when the command should stop. */
    protected function abortIfDisabled(): bool
    {
        if (config('docuccino.enabled', true) === false) {
            $this->error('Docuccino is disabled (set docuccino.enabled = true to run this command).');

            return true;
        }

        return false;
    }
}
