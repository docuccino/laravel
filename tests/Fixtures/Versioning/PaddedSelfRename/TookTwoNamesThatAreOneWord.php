<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\PaddedSelfRename;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RenamedParameter;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/**
 * Two ends that differ only in the whitespace around them, on each side of the vocabulary. Nothing was
 * renamed, and the refusal has to say so — a pair read as typed in one place and trimmed in another
 * lets this through as a change and then reports it as a collision that never happened.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Renames nothing at all.')]
#[RenamedResponseField(schema: FormData::class, from: 'title', to: ' title')]
#[RenamedParameter(in: 'query', from: 'search', to: ' search ')]
final class TookTwoNamesThatAreOneWord {}
