<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Illuminate\Console\Command;

/**
 * Reads a command's string options one way. A flag left off, given nothing, or given whitespace all
 * mean the same thing to the reader — unset — so they answer the same thing here rather than leaving
 * every call site to decide again.
 *
 * @mixin Command
 */
trait StringOptions
{
    /** An option the user actually set, trimmed, or null. */
    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
