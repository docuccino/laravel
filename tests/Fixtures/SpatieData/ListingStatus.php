<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Docuccino\Attributes\CaseDescription;

/** A loadable twin of the fixture app's `App\Enums\ListingStatus`, case for case. */
enum ListingStatus: string
{
    #[CaseDescription('Visible to the public and accepting applications.')]
    case Open = 'open';

    #[CaseDescription('No longer accepting applications.')]
    case Closed = 'closed';

    case Draft = 'draft';
}
