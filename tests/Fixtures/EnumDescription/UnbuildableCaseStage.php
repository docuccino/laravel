<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\EnumDescription;

use Docuccino\Attributes\CaseDescription;

/**
 * A case whose `#[CaseDescription]` PHP cannot construct — an `int` where the attribute takes a string —
 * above a docblock saying what the case means, beside a case described well. Only ever reflected.
 */
enum UnbuildableCaseStage: string
{
    /** Waiting for a reviewer. */
    /* @phpstan-ignore argument.type (the wrong argument type IS the fixture) */
    #[CaseDescription(5)]
    case Held = 'held';

    #[CaseDescription('Out in the world.')]
    case Released = 'released';

    #[CaseDescription('Taken down.')]
    case Withdrawn = 'withdrawn';
}
