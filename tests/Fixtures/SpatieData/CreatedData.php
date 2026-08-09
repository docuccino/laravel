<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * A Data class that OVERRIDES spatie's `calculateResponseStatus()` to a 201 — the shape gap 5 targets.
 * The method is declared in this class's own file, so the reflector recognises it as a real override
 * (not the inherited trait default). Only ever reflected; the returned status the engine folds is
 * scripted by the stub in tests.
 */
final class CreatedData extends Data
{
    public function __construct(
        public string $id,
    ) {}

    protected function calculateResponseStatus(Request $request): int
    {
        return 201;
    }
}
