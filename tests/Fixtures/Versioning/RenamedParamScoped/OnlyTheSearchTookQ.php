<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedParamScoped;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RenamedParameter;

/**
 * The scope as a plain filter, which is all it can be here: a parameter belongs to one operation
 * already, so there is no shared shape to fork and nothing to widen into.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms search takes `search` where it took `q`.')]
#[AppliesTo('GET /api/versioned-search')]
#[RenamedParameter(in: 'query', from: 'q', to: 'search')]
final class OnlyTheSearchTookQ {}
