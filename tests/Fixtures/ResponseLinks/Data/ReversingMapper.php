<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data;

use Spatie\LaravelData\Mappers\NameMapper;

/** A name mapper the application wrote. */
final class ReversingMapper implements NameMapper
{
    public function map(int|string $name): string|int
    {
        return strrev((string) $name);
    }
}
