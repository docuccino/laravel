<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Authorization;

use Docuccino\Attributes\IgnoreResponse;

/**
 * The actions the gate-reachability routes point at. Only ever reflected — the stub engine stands in
 * for what the analyser would recover from these bodies.
 */
final class KioskController
{
    /** @return array<string, mixed> */
    public function index(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function show(Kiosk $kiosk): array
    {
        return [];
    }

    /**
     * The author has already said the 403 is not worth documenting.
     *
     * @return array<string, mixed>
     */
    #[IgnoreResponse(403)]
    public function muted(): array
    {
        return [];
    }
}
