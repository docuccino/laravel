<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use Docuccino\Attributes\CaseDescription;
use Docuccino\Attributes\Description;

/**
 * A backed workbench enum exercising the enum integration: backing values become the schema `enum`
 * member, `#[CaseDescription]` prose becomes `x-enumDescriptions`, and the class-level
 * `#[Description]` becomes the component's own `description` — the one this enum reaches from two
 * producers, a typed property and a validation rule.
 */
#[Description(text: 'Where a widget stands in its publication lifecycle.')]
enum WidgetStatus: string
{
    #[CaseDescription('Not yet visible to applicants.')]
    case Draft = 'draft';

    #[CaseDescription('Live and accepting traffic.')]
    case Published = 'published';

    case Archived = 'archived';
}
