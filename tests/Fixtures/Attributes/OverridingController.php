<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Attributes;

use Docuccino\Attributes\Group;
use Docuccino\Attributes\Summary;

/**
 * Redeclares what its parent declares, so nearest-wins is observable: the singleton `#[Summary]` must
 * answer with THIS text, while the repeatable `#[Group]` collects both, child first.
 */
#[Group('Own Group')]
#[Summary('A summary from the child controller')]
final class OverridingController extends LegacyBaseController
{
    public function index(): array
    {
        return [];
    }
}
