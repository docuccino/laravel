<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Attributes;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\Summary;

/**
 * A base controller carrying class-level attributes its children expect to inherit — the shared
 * grouping an app states once on the abstract parent.
 */
#[Group('Legacy API')]
#[Summary('A summary from the base controller')]
abstract class LegacyBaseController {}
