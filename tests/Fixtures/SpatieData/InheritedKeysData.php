<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The mapper is here and the property is on the base. Spatie resolves the class mapper from the
 * CONCRETE class, so every inherited property is renamed by it too.
 */
#[MapName(SnakeCaseMapper::class)]
final class InheritedKeysData extends InheritedKeysBaseData {}
