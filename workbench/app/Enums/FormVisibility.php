<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use Docuccino\Attributes\CaseDescription;
use Docuccino\Attributes\Description;

/**
 * A backed enum whose EVERY case carries prose, which is what the `x-enumDescriptions` map's own
 * contract turns on: the map is published only where no value is missing from it, so this is the enum a
 * version change moving a value in or out has to keep that property for — or lose it visibly.
 */
#[Description(text: 'Who can see a form.')]
enum FormVisibility: string
{
    #[CaseDescription('Anyone with the link.')]
    case Public = 'public';

    #[CaseDescription('Only signed-in members of the workspace.')]
    case Internal = 'internal';

    #[CaseDescription('Only the people it was sent to.')]
    case Invited = 'invited';
}
