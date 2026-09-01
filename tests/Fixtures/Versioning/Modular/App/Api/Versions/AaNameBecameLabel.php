<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Modular\App\Api\Versions;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/**
 * The OLDER half of a chain that lives in the application's own directory — enumerated FIRST, and
 * applied LAST. It can only rename anything once the module's newer change has put `label` back.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'A form publishes `label` where it published `name`.')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'label')]
final class AaNameBecameLabel {}
