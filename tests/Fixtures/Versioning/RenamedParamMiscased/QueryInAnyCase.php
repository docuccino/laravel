<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedParamMiscased;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;

/** `in: 'Query'` says exactly what `in: 'query'` says, the way `#[IgnoreParam]`'s already does. */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms search takes `search` where it took `q`.')]
#[RenamedParameter(in: 'Query', from: 'q', to: 'search')]
final class QueryInAnyCase {}
