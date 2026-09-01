<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Modular\Modules\Zebra\Api\Versions;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/**
 * The NEWER half of the chain, in the module enumerated LAST and named to sort LAST — so a set ordered
 * by directory, by filename or by whatever the filesystem handed over would apply it after the half
 * that depends on it, and the other half would find nothing to rename.
 */
#[ApiVersionChange(since: '2026-12-01', description: 'A form publishes `title` where it published `label`.')]
#[RenamedResponseField(schema: FormData::class, from: 'label', to: 'title')]
final class ZzLabelBecameTitle {}
