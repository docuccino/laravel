<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedParamUnknownIn;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;

/** A location OpenAPI has not got, which names nothing to look for. */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms search takes `search` where it took `q`.')]
#[RenamedParameter(in: 'body', from: 'q', to: 'search')]
final class TookSearchInTheBody {}
