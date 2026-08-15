<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * An in-process twin of the fixture app's `App\Data\MfaChallengeData` — a `DataCollection` property with
 * no `#[DataCollectionOf]`, so the item class can only come from the generic the engine recovered (or
 * did not). Only ever reflected.
 */
final class MfaChallengeData extends Data
{
    public function __construct(
        public readonly string $pending_authentication_token,
        public readonly DataCollection $mfa_factors,
    ) {}
}
