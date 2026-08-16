<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

/**
 * Routes whose thrown exceptions the suite scripts on the stub engine. The bodies come from the error
 * tiers, so the actions themselves only need to exist and be routable.
 */
final class DeclaredErrorsController
{
    public function first(): array
    {
        return [];
    }

    public function second(): array
    {
        return [];
    }

    public function third(): array
    {
        return [];
    }

    public function fourth(): array
    {
        return [];
    }
}
