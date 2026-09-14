<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\EnumDescription;

use Docuccino\Attributes\Description;

/** Both halves of the declaration, so it says nothing certain and is reported instead. Only ever reflected. */
#[Description(text: 'Something', file: 'docs/stages.md')]
enum UnreadableStage: string
{
    case Held = 'held';

    case Released = 'released';
}
