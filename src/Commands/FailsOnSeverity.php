<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Pipeline\GenerationResult;
use Illuminate\Console\Command;

/**
 * The `--fail-on` policy shared by the commands: `warning` fails on a warning or an error, `error` on
 * an error only, `none` never fails.
 *
 * A value we don't recognise is rejected by {@see validateFailOn()} rather than coerced: coercing a
 * typo would answer "never fail", which silently removes the gate the flag was added to CI to be.
 *
 * @mixin Command
 */
trait FailsOnSeverity
{
    /** @var list<string> */
    private const FAIL_ON_VALUES = ['none', 'warning', 'error'];

    protected function failsOn(GenerationResult $result): bool
    {
        return match ($this->failOn()) {
            'warning' => $result->has(Severity::Error) || $result->has(Severity::Warning),
            'error' => $result->has(Severity::Error),
            default => false,
        };
    }

    /** False (after printing why) when `--fail-on` names something we don't know. */
    protected function validateFailOn(): bool
    {
        if (in_array($this->failOn(), self::FAIL_ON_VALUES, true)) {
            return true;
        }

        $this->error(sprintf(
            'Unknown --fail-on "%s"; expected one of: %s.',
            $this->failOn(),
            implode(', ', self::FAIL_ON_VALUES),
        ));

        return false;
    }

    /** The flag as given; `--fail-on` with no value at all is the same as not passing it. */
    private function failOn(): string
    {
        $value = $this->option('fail-on');

        return is_string($value) ? $value : 'none';
    }
}
