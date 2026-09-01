<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

/**
 * How a path-like config key holds its paths, so {@see ConfigPaths} knows how to walk it.
 *
 * @internal
 */
enum PathShape
{
    /** One string, e.g. `content.dir`. */
    case Single;

    /** A list of strings, e.g. `overlays` and `api_version.changes`. */
    case PathList;

    /** A list of maps each carrying a `path` member, e.g. `export.targets`. */
    case TargetList;
}
