<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Chained;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/** The OLDER of the chained pair, sorting FIRST by name: it can only apply once the newer one has put `label` back. */
#[ApiVersionChange(since: '2026-09-01', description: 'A form publishes `label` where it published `name`.')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'label')]
final class AaNameBecameLabel {}
