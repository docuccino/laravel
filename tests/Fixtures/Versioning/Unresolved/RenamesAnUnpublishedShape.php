<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\Unresolved;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedResponseField;

/** Names a class this document publishes no schema for. */
#[ApiVersionChange(since: '2026-09-01', description: 'Renames a field of a shape nothing publishes.')]
#[RenamedResponseField(schema: 'App\\Http\\Resources\\NothingResource', from: 'name', to: 'title')]
final class RenamesAnUnpublishedShape {}
