<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\PaddedRename;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;

/**
 * The other half of the same normalisation: a declaration padded with whitespace still names the class
 * and the field it means. Written with the class as a string so the padding survives, which `::class`
 * would not allow.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'A form publishes `title` where it published `name`.')]
#[RenamedResponseField(schema: ' Workbench\App\Data\FormData ', from: ' name', to: 'title ')]
final class TookANameWithRoomAroundIt {}
