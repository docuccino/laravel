<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/**
 * A shared base holding the fields several payloads have in common — the reuse spatie collects parent
 * class attributes for. It names no wire convention of its own; {@see InheritedKeysData} does, and the
 * key its mapper produces is the one the API sends for this property.
 */
abstract class InheritedKeysBaseData extends Data
{
    public function __construct(
        public string $displayName,
    ) {}
}
