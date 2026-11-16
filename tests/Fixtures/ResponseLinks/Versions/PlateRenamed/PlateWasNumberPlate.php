<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Versions\PlateRenamed;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Data\VanData;

/** A van's plate, which versions before the change send as `number_plate`. */
#[ApiVersionChange(since: '2026-09-01', description: 'A van sends `plate` where it sent `number_plate`.')]
#[RenamedResponseField(schema: VanData::class, from: 'number_plate', to: 'plate')]
final class PlateWasNumberPlate {}
