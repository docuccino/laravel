<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Pipeline\GenerationResult;
use Illuminate\Console\Command;

/**
 * The `--fail-on` policy shared by the commands: `warning` fails on a warning or an error, `error` on
 * an error only, anything else never fails.
 *
 * @mixin Command
 */
trait FailsOnSeverity
{
    protected function failsOn(GenerationResult $result): bool
    {
        return match (is_string($this->option('fail-on')) ? $this->option('fail-on') : 'none') {
            'warning' => $result->has(Severity::Error) || $result->has(Severity::Warning),
            'error' => $result->has(Severity::Error),
            default => false,
        };
    }
}
