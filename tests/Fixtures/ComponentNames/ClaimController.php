<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames;

/**
 * Routes that each stake one claim on a component name, so the claims arise ACROSS routes the way
 * they do in a real app: a Data class arriving as a request body and the same class leaving as a
 * response, two `#[SchemaId]`-pinned classes of one short name, and a class the analyser cannot
 * expand beside a working one that shares its name, and two classes of one short name whose shapes
 * coincide.
 */
final class ClaimController
{
    /** The Data class as a request body — spatie's rules, not its properties. */
    public function store(PortalData $portal): array
    {
        return [];
    }

    /** The same class as a response — its properties, a different shape. */
    public function show(): array
    {
        return [];
    }

    public function apiUser(): array
    {
        return [];
    }

    public function adminUser(): array
    {
        return [];
    }

    public function brokenGizmo(): array
    {
        return [];
    }

    public function billingReceipt(): array
    {
        return [];
    }

    public function supportReceipt(): array
    {
        return [];
    }

    public function workingGizmo(): array
    {
        return [];
    }
}
