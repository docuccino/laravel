<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedParamSelf;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;

/** A parameter renamed to itself, which describes a change nobody made. */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms search takes `search`.')]
#[RenamedParameter(in: 'query', from: 'search', to: 'search')]
final class TookSearchAsSearch {}
