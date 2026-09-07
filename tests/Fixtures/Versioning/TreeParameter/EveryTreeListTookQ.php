<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\TreeParameter;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;

/** The unscoped rename, for the two paths that address ONE path item between them. */
#[ApiVersionChange(since: '2026-09-01', description: 'The tree lists take `search` where they took `q`.')]
#[RenamedParameter(in: 'query', from: 'q', to: 'search')]
final class EveryTreeListTookQ {}
