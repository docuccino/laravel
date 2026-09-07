<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\ScopedTreeParameter;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RenamedParameter;

/** A parameter rename narrowed to one of two operations, for the fork rule's other half. */
#[ApiVersionChange(since: '2026-09-01', description: 'The tree list takes `search` where it took `q`.')]
#[AppliesTo('GET /api/versioned-trees')]
#[RenamedParameter(in: 'query', from: 'q', to: 'search')]
final class OnlyTheTreeListTookQ {}
