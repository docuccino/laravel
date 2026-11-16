<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ResponseLinks\Versions\ReadBackAdded;

use Docuccino\Attributes\Versioning\AddedOperation;
use Docuccino\Attributes\Versioning\ApiVersionChange;

/** A parcel's read route, which versions before the change do not serve. */
#[ApiVersionChange(since: '2026-09-01', description: 'A parcel can be read back.')]
#[AddedOperation('GET /api/parcels/{parcel}')]
final class ParcelsCanBeReadBack {}
