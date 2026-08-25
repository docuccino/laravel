<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\KebabCaseMapper;

/**
 * A Data class keyed by spatie's kebab-case mapper — the newest of its built-in mappers, and the one
 * the reflector's table went short of. Only reflected; the attribute is never instantiated, so this
 * loads on an installed version that predates the mapper.
 */
#[MapName(KebabCaseMapper::class)]
final class KebabCasedData extends Data
{
    public function __construct(
        public string $displayName,
        public string $userName,
    ) {}
}
