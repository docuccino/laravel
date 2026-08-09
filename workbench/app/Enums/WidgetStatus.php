<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use Docuccino\Attributes\CaseDescription;

/**
 * A backed workbench enum exercising the enum integration: backing values become the schema `enum`
 * member and `#[CaseDescription]` prose becomes `x-enumDescriptions`.
 */
enum WidgetStatus: string
{
    #[CaseDescription('Not yet visible to applicants.')]
    case Draft = 'draft';

    #[CaseDescription('Live and accepting traffic.')]
    case Published = 'published';

    case Archived = 'archived';
}
