<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Semver;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/** The OLDER of the pair — `1.9.0`, which sorts AFTER `1.10.0` bytewise and before it as a version. Its FQCN sorts FIRST. */
#[ApiVersionChange(since: '1.9.0', description: 'A form publishes `label` where it published `name`.')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'label')]
final class AaNameBecameLabel {}
