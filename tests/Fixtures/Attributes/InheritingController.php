<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Attributes;

/**
 * Declares nothing of its own, so everything documentable comes off the parent — the inheritance
 * half of the collector's walk.
 */
final class InheritingController extends LegacyBaseController
{
    public function index(): array
    {
        return [];
    }

    /**
     * Lists the archived widgets.
     *
     * @deprecated Superseded by the v2 listing.
     */
    public function archived(): array
    {
        return [];
    }
}
