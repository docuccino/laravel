<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EnumValueAdded;

use Docuccino\Attributes\Versioning\AddedEnumValue;
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Workbench\App\Enums\FormVisibility;

/** A value this version added, so the versions before it never published it. */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms can be shared with named invitees.')]
#[AddedEnumValue(enum: FormVisibility::class, value: 'invited')]
final class FormsGainedAnInvitedVisibility {}
