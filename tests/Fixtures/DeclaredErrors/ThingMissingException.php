<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\DeclaredErrors;

use Docuccino\Attributes\ErrorComponent;
use RuntimeException;

/** An application exception naming the error it stands for — the whole of the simple case. */
#[ErrorComponent('ResourceMissing')]
final class ThingMissingException extends RuntimeException {}
