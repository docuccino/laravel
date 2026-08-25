<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Mappers\NameMapper;

/**
 * An application's own name mapper — spatie's contract, none of spatie's implementations. Whatever it
 * does at runtime is arbitrary code, so the wire names it produces cannot be recovered from the class.
 */
final class ReversedNameMapper implements NameMapper
{
    public function map(string|int $name): string|int
    {
        return strrev((string) $name);
    }
}
