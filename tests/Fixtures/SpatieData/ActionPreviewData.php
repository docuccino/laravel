<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

/**
 * The in-process twin of the fixture app's `App\Data\ActionPreviewData` — the reflector reads attributes
 * off the FQCN it is handed, so the real engine's recovered TYPES are re-keyed onto this. Keep the
 * properties and attributes identical to the fixture app's. Only ever reflected.
 */
final class ActionPreviewData extends Data
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $metadata
     * @param  list<string>  $touched_fields
     */
    public function __construct(
        public readonly array $config = [],
        #[Nullable]
        public readonly ?array $metadata = null,
        public readonly array $touched_fields = [],
    ) {}
}
