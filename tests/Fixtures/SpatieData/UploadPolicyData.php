<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * An in-process twin of the fixture app's `App\Data\UploadPolicyData`: a natively typed, `#[StringType]`
 * property whose static `rules()` allow-lists it against a list only the runtime knows. Only ever
 * reflected.
 */
final class UploadPolicyData extends Data
{
    public function __construct(
        #[StringType]
        public readonly string $collection = 'default',
    ) {}
}
