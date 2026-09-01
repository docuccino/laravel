<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedUnresolved;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;

/** A removal naming a class this document publishes no schema for. */
#[ApiVersionChange(since: '2026-09-01', description: 'Ledgers no longer publish `subtotal`.')]
#[RemovedResponseField(schema: 'App\\Data\\LedgerData', field: 'subtotal', type: 'integer')]
final class LostAFieldOfAnUnpublishedShape {}
