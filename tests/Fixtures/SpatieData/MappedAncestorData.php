<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The other way round from {@see InheritedKeysData}: a base that fixes the wire convention for every
 * payload under it, none of which repeats the attribute. Spatie walks to here from the subclass.
 */
#[MapName(SnakeCaseMapper::class)]
abstract class MappedAncestorData extends Data {}
