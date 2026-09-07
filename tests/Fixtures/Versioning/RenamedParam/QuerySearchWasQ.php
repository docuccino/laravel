<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedParam;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;

/**
 * A query parameter that went by another name. It names no class, because a parameter belongs to the
 * operation rather than to a shape — `?search=` is a member of a request line, not of a body.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms search takes `search` where it took `q`.')]
#[RenamedParameter(in: 'query', from: 'q', to: 'search')]
final class QuerySearchWasQ {}
