<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedParamScopedNowhere;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RenamedParameter;

/** A scope naming an operation this document does not publish — indistinguishable from a change nobody declared. */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms search takes `search` where it took `q`.')]
#[AppliesTo('GET /api/forms-that-moved')]
#[RenamedParameter(in: 'query', from: 'q', to: 'search')]
final class ARouteThatMovedTookQ {}
