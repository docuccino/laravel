<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedParamEveryLocation;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;

/** One rename per location a parameter can be renamed in, on one operation that declares all four. */
#[ApiVersionChange(since: '2026-09-01', description: 'A form is located by `fields`, `X-Trace` and `session`.')]
#[RenamedParameter(in: 'query', from: 'columns', to: 'fields')]
#[RenamedParameter(in: 'header', from: 'X-Trace-Id', to: 'X-Trace')]
#[RenamedParameter(in: 'cookie', from: 'sid', to: 'session')]
final class TookThreeOtherNames {}
