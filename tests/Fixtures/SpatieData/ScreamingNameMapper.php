<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Mappers\NameMapper;

/**
 * An application's OWN name mapper — a legitimate thing to write, and one no table can know the
 * transform of. Nothing here is ever executed: the reflector reads the attribute, not the mapper.
 */
final class ScreamingNameMapper implements NameMapper
{
    public function map(int|string $name): string|int
    {
        return strtoupper((string) $name).'!';
    }
}
