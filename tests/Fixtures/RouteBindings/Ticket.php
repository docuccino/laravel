<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteBindings;

/**
 * A bound class that is not an Eloquent model — Laravel's `UrlRoutable` shape, which an app may
 * implement on anything. Nothing can type a column on it, so the parameter degrades to a string.
 */
final class Ticket
{
    public string $reference = '';
}
