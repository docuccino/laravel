<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use Docuccino\Attributes\CaseDescription;

/**
 * A backed enum documenting its cases with plain docblock summaries rather than
 * `#[CaseDescription]`, to exercise the docblock fallback for `x-enumDescriptions`. One case carries
 * both a docblock and the attribute, proving the attribute wins; one case carries neither and is
 * omitted from the descriptions map.
 */
enum Season: string
{
    /** Warm and dry. */
    case Summer = 'summer';

    /**
     * This docblock summary is ignored because the attribute takes precedence.
     */
    #[CaseDescription('Cold and wet.')]
    case Winter = 'winter';

    case Spring = 'spring';
}
