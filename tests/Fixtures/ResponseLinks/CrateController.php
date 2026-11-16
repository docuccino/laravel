<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks;

/**
 * A crate made three ways, and read by every column it could be bound on. Never dispatched.
 */
final class CrateController
{
    public function store(): Crate
    {
        return Crate::create([]);
    }

    public function storeKeyed(): KeyedCrateResource
    {
        return new KeyedCrateResource(Crate::create([]));
    }

    public function storeBranching(): BranchingCrateResource
    {
        return new BranchingCrateResource(Crate::create([]));
    }

    public function show(Crate $crate): Crate
    {
        return $crate;
    }
}
