<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

/**
 * A pamphlet made and read back. Never dispatched.
 */
final class PamphletController
{
    public function store(): Pamphlet
    {
        return Pamphlet::create(['published' => true]);
    }

    public function show(Pamphlet $pamphlet): Pamphlet
    {
        return $pamphlet;
    }
}
