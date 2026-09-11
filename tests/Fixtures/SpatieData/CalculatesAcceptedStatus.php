<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\Request;

/**
 * The other shape a shared status calculation is written in. A trait method reports the trait's file
 * while reporting the USING class as its declarer, so the two halves of the reflection answer point at
 * different places — which is exactly why the file, and not the class, decides whose body this is.
 */
trait CalculatesAcceptedStatus
{
    protected function calculateResponseStatus(Request $request): int
    {
        return 202;
    }
}
