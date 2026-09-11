<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/**
 * The success status written once for every payload under it — a base class, which is where an
 * application puts a rule that applies to more than one endpoint. Its file is neither the subclass's
 * nor spatie's concern's, and spatie runs it all the same. Only ever reflected.
 */
abstract class ApiStatusBaseData extends Data
{
    protected function calculateResponseStatus(Request $request): int
    {
        return 202;
    }
}
