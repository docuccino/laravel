<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;

/**
 * An in-process twin of the fixture app's `App\Data\SnapshotData`: same property names, same order, no
 * presentation attributes. The mapper reflects the class it is asked about, so the real engine's
 * recovered types are seeded onto this loadable class to emit a schema from them. Only ever reflected.
 */
final class SnapshotData extends Data
{
    public function __construct(
        public readonly int $snapshot_schema_version,
        public readonly array $context,
        public readonly array $candidate,
        public readonly array $theme_data,
        public readonly array $forms,
        public readonly array $permissions,
        public readonly array $attachments,
    ) {}
}
