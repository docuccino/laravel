<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * An int-backed enum whose backing values are a contiguous zero-based run, every case described — the
 * one shape whose value-keyed description map is a PHP list, and so the one that proves the map is
 * emitted as a JSON object anyway.
 */
enum WidgetTier: int
{
    /** No paid features. */
    case Free = 0;

    /** The paid default. */
    case Standard = 1;

    /** Everything, plus support. */
    case Premium = 2;
}
