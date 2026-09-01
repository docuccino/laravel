<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RemovedPairReversed;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedResponseField;
use Workbench\App\Data\FormData;

/** The same pair, written the other way round — which is an order an AttributeSet really does keep. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms no longer publish `subtotal` or `archivedAt`.')]
#[RemovedResponseField(schema: FormData::class, field: 'archivedAt', type: 'string')]
#[RemovedResponseField(schema: FormData::class, field: 'subtotal', type: 'integer')]
final class FormLostTwoFieldsTheOtherWayRound {}
