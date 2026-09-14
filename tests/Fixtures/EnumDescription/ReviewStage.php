<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\EnumDescription;

use Docuccino\Attributes\Description;

/** An enum that states one publishable sentence about itself. Only ever reflected. */
#[Description(text: 'How far through review a submission has got.')]
enum ReviewStage: string
{
    case Queued = 'queued';

    case Approved = 'approved';
}
