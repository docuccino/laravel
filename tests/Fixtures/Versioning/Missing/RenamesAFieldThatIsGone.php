<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Missing;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/** The declaration rotted: the code has no `headline` any more. */
#[ApiVersionChange(since: '2026-09-01', description: 'Renames a form field.')]
#[RenamedResponseField(schema: FormData::class, from: 'caption', to: 'headline')]
final class RenamesAFieldThatIsGone {}
