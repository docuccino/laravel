<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedParamTaken;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;

/** A rename onto a name the operation already declares, which would collapse two parameters into one. */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms search takes `search` where it took `trace_id`.')]
#[RenamedParameter(in: 'query', from: 'trace_id', to: 'search')]
final class TookSearchAsTraceId {}
