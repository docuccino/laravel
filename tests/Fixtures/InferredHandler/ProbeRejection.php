<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\InferredHandler;

use RuntimeException;

/**
 * An application exception carrying no HTTP status a build can read — the shape an `HttpException`
 * subclass whose constructor sets its status dynamically also arrives in. A leaf of its own so a render
 * callback typed against it matches nothing else the workbench throws.
 */
final class ProbeRejection extends RuntimeException {}
