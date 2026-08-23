<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * A backed enum whose every case carries prose — the shape that earns the value-keyed
 * `x-enumDescriptions` map beside the index-parallel array.
 */
enum WidgetKind: string
{
    /** A shippable, tangible widget. */
    case Physical = 'physical';

    /** A download; nothing ships. */
    case Digital = 'digital';
}
