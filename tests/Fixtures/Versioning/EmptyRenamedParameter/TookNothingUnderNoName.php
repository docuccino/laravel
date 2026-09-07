<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EmptyRenamedParameter;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;

/** A parameter rename with an empty end, which names nothing to move. */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms search took something.')]
#[RenamedParameter(in: 'query', from: 'q', to: '')]
final class TookNothingUnderNoName {}
