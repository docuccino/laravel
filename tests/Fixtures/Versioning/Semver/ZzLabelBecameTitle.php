<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Semver;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/** The NEWER of the pair, deliberately named so its FQCN sorts last. */
#[ApiVersionChange(since: '1.10.0', description: 'A form publishes `title` where it published `label`.')]
#[RenamedResponseField(schema: FormData::class, from: 'label', to: 'title')]
final class ZzLabelBecameTitle {}
