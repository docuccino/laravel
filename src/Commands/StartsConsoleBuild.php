<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Hands this command's input to {@see MemoryLimitOption} before `handle()` runs, whose injected
 * dependencies build the engine. The command's own hook rather than the framework's `CommandStarting`
 * event, which Laravel never dispatches while `APP_ENV` is `testing`.
 *
 * @mixin Command
 */
trait StartsConsoleBuild
{
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        MemoryLimitOption::capture($input);
    }
}
