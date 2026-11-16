<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Versions\TrackingRenamed;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\ParcelResource;

/** The parcel's tracking code, which versions before the change send as `tracking_code`. */
#[ApiVersionChange(since: '2026-09-01', description: 'A parcel sends `tracking` where it sent `tracking_code`.')]
#[RenamedResponseField(schema: ParcelResource::class, from: 'tracking_code', to: 'tracking')]
final class TrackingCodeRenamed {}
