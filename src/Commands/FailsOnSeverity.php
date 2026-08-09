<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Pipeline\GenerationResult;
use Illuminate\Console\Command;

/**
 * The single owner of the `--fail-on` policy shared by the artisan commands: `warning` fails on any
 * warning or error, `error` fails on an error only, anything else never fails. The severity scan
 * lives on {@see GenerationResult::has()} so this is the only home for the option-to-severity map.
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
