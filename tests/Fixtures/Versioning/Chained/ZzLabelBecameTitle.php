<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Chained;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/**
 * The NEWER of a chained pair, and the one whose FQCN sorts LAST — so a walk that read the directory in
 * name order, or in the order the filesystem handed the files over, would apply the two the wrong way
 * round and the first of them would find nothing to rename.
 */
#[ApiVersionChange(since: '2026-12-01', description: 'A form publishes `title` where it published `label`.')]
#[RenamedResponseField(schema: FormData::class, from: 'label', to: 'title')]
final class ZzLabelBecameTitle {}
