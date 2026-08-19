<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Attributes\Mock;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;

/**
 * A second in-process twin of the fixture app's `App\Data\SnapshotData`, carrying the `#[Mock]` shapes
 * on top of the same property list: a property-level hint, a class-level one naming a member, and one
 * on a `#[MapName]`d property so the hint has to follow it to the key it publishes under. Only ever
 * reflected.
 */
#[Mock(faker: 'numberBetween:1,9', property: 'permissions')]
final class MockedSnapshotData extends Data
{
    public function __construct(
        #[Mock(faker: 'randomDigit', seedGroup: 'snapshot')]
        public readonly int $snapshot_schema_version,
        public readonly array $context,
        #[MapName('profile')]
        #[Mock(faker: 'safeEmail')]
        public readonly array $candidate,
        public readonly array $theme_data,
        public readonly array $forms,
        public readonly array $permissions,
        public readonly array $attachments,
    ) {}
}
