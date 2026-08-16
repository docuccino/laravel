<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\Max;

/**
 * Carries no attribute of its own — {@see BaseApiData} does — so this documents as merging only if the
 * reflector walks the parent chain the way spatie's own attribute collection does. Only ever reflected.
 */
final class InheritedMergeRulesData extends BaseApiData
{
    public function __construct(
        #[Max(255)]
        public readonly string $name,
    ) {}
}
