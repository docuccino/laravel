<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\ScopedAll;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\AppliesTo;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/**
 * Scoped by a wildcard that covers EVERY operation publishing `FormData`, which must come out
 * byte-identical to the same change written with no scope at all.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'A form publishes `title` where it published `name`.')]
#[AppliesTo('GET /api/versioned-forms*')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]
final class EveryFormsOperationRenamedTitle {}
