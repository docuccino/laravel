<?php

declare(strict_types=1);

namespace Workbench\App\Data;

use Workbench\App\Enums\FormVisibility;
use Workbench\App\Enums\WidgetPriority;

/**
 * A form carrying the two value sets a version change addresses: one whose every value is described, so
 * the document publishes the prose MAP, and an int-backed one where only some are, so it publishes the
 * positional array instead. The two decoration shapes are read back differently, and a set edited
 * through one of them is the point of the pair.
 */
final class VisibleFormData
{
    public function __construct(
        public int $id,
        public FormVisibility $visibility,
        public WidgetPriority $priority,
    ) {}
}
