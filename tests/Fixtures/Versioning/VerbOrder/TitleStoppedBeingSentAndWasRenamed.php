<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\VerbOrder;

use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\MadeResponseFieldRequired;
use Docuccino\Attributes\Versioning\RenamedResponseField;
use Workbench\App\Data\FormData;

/**
 * One change carrying two KINDS of verb over one field, which is the case an AttributeSet cannot
 * answer: it reads per attribute type, so the order these are written in is gone by the time anything
 * asks, and something has to decide.
 *
 * Both name today's spelling — `title` — because that is the direction the vocabulary runs in. Applied
 * required-first, the guarantee comes off `title` and the rename then re-spells what is left, so the
 * older document publishes `name` and requires only `id`. Applied rename-first, `title` is already
 * standing under its old name when the required verb looks for it, so nothing is edited and the build
 * reports a declaration that is perfectly correct as rotted.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'A form publishes `title`, and always sends it; before this it published `name` and could omit it.')]
#[MadeResponseFieldRequired(schema: FormData::class, field: 'title')]
#[RenamedResponseField(schema: FormData::class, from: 'name', to: 'title')]
final class TitleStoppedBeingSentAndWasRenamed {}
