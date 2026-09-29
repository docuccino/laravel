<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteConstraints;

use Docuccino\Attributes\PathParameter;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;

/**
 * Actions whose path segments are constrained by the ROUTE (`->whereUuid()`, `->where()`,
 * `Route::pattern()`), never by the signature: each segment is a plain string, so whatever the
 * document says beyond `string` came from the route's constraints.
 */
final class ConstraintController
{
    public function item(string $item): array
    {
        return [];
    }

    public function code(string $code): array
    {
        return [];
    }

    public function pair(string $first, string $second): array
    {
        return [];
    }

    #[PathParameter('item', type: 'int', description: 'The item number.')]
    public function declared(string $item): array
    {
        return [];
    }

    #[PathParameter('item', format: 'uuid', description: 'The item.')]
    public function described(string $item): array
    {
        return [];
    }

    public function vault(Vault $vault): array
    {
        return [];
    }
}
