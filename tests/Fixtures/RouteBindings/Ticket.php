<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteBindings;

use Illuminate\Contracts\Routing\UrlRoutable;

/**
 * A bound class that is not an Eloquent model — Laravel's `UrlRoutable` shape, which an app may
 * implement on anything. Nothing can type a column on it, so the parameter degrades to a string.
 */
final class Ticket implements UrlRoutable
{
    public string $reference = '';

    public function getRouteKey(): string
    {
        return $this->reference;
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function resolveRouteBinding($value, $field = null): ?static
    {
        return null;
    }

    public function resolveChildRouteBinding($childType, $value, $field): ?static
    {
        return null;
    }
}
