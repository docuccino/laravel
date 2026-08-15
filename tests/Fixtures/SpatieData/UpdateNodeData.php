<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * An in-process twin of the fixture app's `App\Data\UpdateNodeData`: a partial-update DTO whose static
 * `rules()` names `label`, a field the class deliberately has no property for, and which carries a
 * positional `array{float, float}` tuple. Only ever reflected.
 */
final class UpdateNodeData extends Data
{
    public function __construct(
        #[StringType, Max(255)]
        public readonly Optional|string $name = new Optional,
        #[Nullable]
        public readonly Optional|array|null $metadata = new Optional,
        public readonly Optional|array $position = new Optional,
    ) {}
}
