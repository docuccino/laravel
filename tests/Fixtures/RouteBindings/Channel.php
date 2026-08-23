<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteBindings;

/**
 * A PURE enum on a route signature. Laravel's implicit binding resolves backed enums only, so this
 * hint never binds at runtime — the honest documentation is a plain string, not the cases.
 */
enum Channel
{
    case Web;
    case Mobile;
}
