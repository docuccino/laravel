<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

/**
 * A reel made and read back, answering with the model itself. Never dispatched.
 */
final class ReelController
{
    public function store(): Reel
    {
        return Reel::create([]);
    }

    public function show(Reel $reel): Reel
    {
        return $reel;
    }
}
