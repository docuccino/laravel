<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\RenamedParamMissing;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;

/** A rename over a parameter no operation this document publishes declares. */
#[ApiVersionChange(since: '2026-09-01', description: 'The forms search takes `sort` where it took `order`.')]
#[RenamedParameter(in: 'query', from: 'order', to: 'sort')]
final class TookASortNobodyDeclares {}
