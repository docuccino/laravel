<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Versioning\EnumValuePair;

use Docuccino\Attributes\Versioning\AddedEnumValue;
use Docuccino\Attributes\Versioning\ApiVersionChange;
use Docuccino\Attributes\Versioning\RemovedEnumValue;
use Workbench\App\Enums\FormVisibility;

/**
 * Both directions on one set, in one change: a value arrived and another left. What it proves is that
 * the second verb reads the set the FIRST one left, rather than the one the code publishes.
 */
#[ApiVersionChange(since: '2026-09-01', description: 'Forms took `invited` in place of `unlisted`.')]
#[AddedEnumValue(enum: FormVisibility::class, value: 'invited')]
#[RemovedEnumValue(enum: FormVisibility::class, value: 'unlisted', name: 'Unlisted', description: 'Reachable by link, and left out of every index.')]
final class FormsGainedTwoVisibilities {}
